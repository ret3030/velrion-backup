<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export a import databáze + hledej-a-nahraď domény (i uvnitř serializovaných dat).
 * Pracuje jen s tabulkami tohoto webu (podle prefixu), takže na hostingu se sdílenou
 * databází nesahá na tabulky jiných webů.
 */
class Velrion_Backup_DB {

	const ROWS_PER_BATCH = 500;

	/** Max. velikost jednoho INSERTu - bezpečně pod výchozím max_allowed_packet MySQL. */
	const MAX_INSERT_BYTES = 1048576;

	public static function tables() {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
	}

	/**
	 * @throws Exception
	 */
	public static function dump( $file_path ) {
		global $wpdb;

		$handle = fopen( $file_path, 'w' );
		if ( ! $handle ) {
			throw new Exception( 'Nelze vytvořit soubor pro export databáze: ' . $file_path );
		}

		fwrite( $handle, "-- Velrion Backup - export databáze\n" );
		fwrite( $handle, '-- Vytvořeno: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n" );
		fwrite( $handle, "SET NAMES utf8mb4;\n" );
		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

		foreach ( self::tables() as $table ) {
			self::dump_table( $handle, $table );
		}

		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );

		if ( ! fclose( $handle ) ) {
			throw new Exception( 'Export databáze se nepodařilo zapsat (plný disk?).' );
		}
	}

	private static function dump_table( $handle, $table ) {
		global $wpdb;

		fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );

		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		if ( ! isset( $create[1] ) ) {
			throw new Exception( 'Nelze přečíst strukturu tabulky ' . $table . ': ' . $wpdb->last_error );
		}
		fwrite( $handle, $create[1] . ";\n" );

		$offset = 0;
		while ( true ) {
			$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT " . self::ROWS_PER_BATCH . " OFFSET {$offset}", ARRAY_A );
			if ( empty( $rows ) ) {
				break;
			}

			$header = 'INSERT INTO `' . $table . '` (`' . implode( '`, `', array_keys( $rows[0] ) ) . "`) VALUES\n";
			$values = array();
			$bytes  = 0;

			foreach ( $rows as $row ) {
				$escaped = array();
				foreach ( $row as $value ) {
					// _real_escape() + remove_placeholder_escape(): esc_sql() nahrazuje znak "%"
					// náhodným řetězcem platným jen v rámci jednoho požadavku. V exportu by tak
					// místo každého "%" zůstal nesmysl a obnova by data poškodila.
					$escaped[] = null === $value ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $value ) ) . "'";
				}
				$line = '(' . implode( ', ', $escaped ) . ')';

				if ( $values && $bytes + strlen( $line ) > self::MAX_INSERT_BYTES ) {
					fwrite( $handle, $header . implode( ",\n", $values ) . ";\n" );
					$values = array();
					$bytes  = 0;
				}

				$values[] = $line;
				$bytes   += strlen( $line );
			}

			fwrite( $handle, $header . implode( ",\n", $values ) . ";\n" );

			$offset += self::ROWS_PER_BATCH;
		}

		fwrite( $handle, "\n" );
	}

	/**
	 * Naimportuje export vytvořený metodou dump(). Čte soubor po řádcích, takže zvládne
	 * i velké databáze: dump() escapuje konce řádků v datech, příkaz proto vždy končí
	 * středníkem na konci řádku.
	 *
	 * @param bool $legacy Export z verze 1.x: ta místo každého "%" uložila zástupný řetězec
	 *                     "{64 hex znaků}" (viz dump()). Vrátíme ho zpět na "%" - délky
	 *                     v serializovaných datech odpovídají původnímu textu, takže sedí.
	 * @throws Exception
	 */
	public static function restore( $file_path, $old_prefix, $new_prefix, $legacy = false ) {
		global $wpdb;

		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			throw new Exception( 'Nelze přečíst export databáze.' );
		}

		$rename    = $old_prefix !== '' && $old_prefix !== $new_prefix;
		$statement = '';

		while ( false !== ( $line = fgets( $handle ) ) ) {
			if ( '' === $statement && ( 0 === strpos( $line, '--' ) || '' === trim( $line ) ) ) {
				continue;
			}

			$statement .= $line;

			if ( ';' !== substr( rtrim( $line ), -1 ) ) {
				continue;
			}

			if ( $legacy ) {
				$statement = preg_replace( '/\{[0-9a-f]{64}\}/', '%', $statement );
			}

			if ( $rename ) {
				$from = '/`' . preg_quote( $old_prefix, '/' ) . '/';
				// U INSERTu jen název tabulky, aby se nepřepsala data obsahující stejný text.
				$statement = 0 === strpos( $statement, 'INSERT' )
					? preg_replace( $from, '`' . $new_prefix, $statement, 1 )
					: preg_replace( $from, '`' . $new_prefix, $statement );
			}

			if ( false === $wpdb->query( $statement ) ) {
				fclose( $handle );
				throw new Exception( 'Chyba při importu databáze: ' . $wpdb->last_error );
			}

			$statement = '';
		}

		fclose( $handle );

		if ( $rename ) {
			self::fix_prefix_dependent_data( $old_prefix, $new_prefix );
		}
	}

	/**
	 * WordPress má prefix tabulek i v datech - role a oprávnění uživatelů jsou uložené pod
	 * klíči "{prefix}user_roles" / "{prefix}capabilities". Bez opravy by po obnově na web
	 * s jiným prefixem nefungovalo přihlášení.
	 */
	private static function fix_prefix_dependent_data( $old_prefix, $new_prefix ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$new_prefix}options` SET option_name = %s WHERE option_name = %s",
				$new_prefix . 'user_roles',
				$old_prefix . 'user_roles'
			)
		);

		foreach ( array( 'capabilities', 'user_level' ) as $suffix ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$new_prefix}usermeta` SET meta_key = %s WHERE meta_key = %s",
					$new_prefix . $suffix,
					$old_prefix . $suffix
				)
			);
		}
	}

	/**
	 * Nahradí $from za $to ve všech textových sloupcích tabulek webu, bezpečně i uvnitř
	 * serializovaných hodnot (opraví délkové prefixy). Používá se při obnově na jiné doméně.
	 */
	public static function search_replace( $from, $to ) {
		global $wpdb;

		if ( '' === $from || $from === $to ) {
			return;
		}

		foreach ( self::tables() as $table ) {
			$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
			if ( count( $keys ) !== 1 ) {
				continue; // Bez jednoznačného klíče řádky bezpečně neaktualizujeme.
			}
			$primary = $keys[0]['Column_name'];

			$columns = array();
			foreach ( $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ) as $column ) {
				if ( preg_match( '/^(var)?char|text$/i', preg_replace( '/\(.*$/', '', $column['Type'] ) ) ) {
					$columns[] = $column['Field'];
				}
			}
			if ( ! $columns ) {
				continue;
			}

			$select = '`' . $primary . '`, `' . implode( '`, `', $columns ) . '`';
			$offset = 0;

			while ( $rows = $wpdb->get_results( "SELECT {$select} FROM `{$table}` LIMIT " . self::ROWS_PER_BATCH . " OFFSET {$offset}", ARRAY_A ) ) {
				foreach ( $rows as $row ) {
					$updates = array();
					foreach ( $columns as $column ) {
						if ( null === $row[ $column ] || false === strpos( $row[ $column ], $from ) ) {
							continue;
						}
						$replaced = self::replace_value( $from, $to, $row[ $column ] );
						if ( $replaced !== $row[ $column ] ) {
							$updates[ $column ] = $replaced;
						}
					}
					if ( $updates ) {
						$wpdb->update( $table, $updates, array( $primary => $row[ $primary ] ) );
					}
				}
				$offset += self::ROWS_PER_BATCH;
			}
		}
	}

	private static function replace_value( $from, $to, $data ) {
		if ( is_string( $data ) ) {
			if ( is_serialized( $data ) ) {
				$value = @unserialize( $data, array( 'allowed_classes' => false ) );
				if ( false !== $value || 'b:0;' === $data ) {
					return serialize( self::replace_value( $from, $to, $value ) );
				}
			}
			return str_replace( $from, $to, $data );
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::replace_value( $from, $to, $value );
			}
			return $data;
		}

		// Objekty neznámých tříd (__PHP_Incomplete_Class) nejdou upravovat - necháme je beze změny.
		if ( is_object( $data ) && ! $data instanceof __PHP_Incomplete_Class ) {
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$data->$key = self::replace_value( $from, $to, $value );
			}
		}

		return $data;
	}
}
