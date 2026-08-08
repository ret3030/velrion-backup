<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SQL dump, import a bezpečné (serializaci respektující) hledej-a-nahraď napříč celou DB.
 */
class Velrion_Backup_DB {

	const ROWS_PER_BATCH = 500;

	/**
	 * @param string $file_path Cílový .sql soubor.
	 * @throws Exception Když se soubor nepodaří otevřít nebo zapsat.
	 */
	public static function dump( $file_path ) {
		global $wpdb;

		$handle = fopen( $file_path, 'w' );
		if ( ! $handle ) {
			throw new Exception( 'Nelze vytvořit soubor pro SQL dump: ' . $file_path );
		}

		fwrite( $handle, "-- Velrion Backup - DB dump\n" );
		fwrite( $handle, '-- Vytvořeno: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n" );
		fwrite( $handle, "SET NAMES utf8mb4;\n" );
		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

		$tables = $wpdb->get_col( 'SHOW TABLES' );

		foreach ( $tables as $table ) {
			self::dump_table( $handle, $table );
		}

		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $handle );
	}

	private static function dump_table( $handle, $table ) {
		global $wpdb;

		fwrite( $handle, "-- ----------------------------\n" );
		fwrite( $handle, "-- Tabulka: {$table}\n" );
		fwrite( $handle, "-- ----------------------------\n" );
		fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );

		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		if ( isset( $create[1] ) ) {
			fwrite( $handle, $create[1] . ";\n\n" );
		}

		$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		if ( $row_count === 0 ) {
			return;
		}

		$columns = null;
		$offset  = 0;

		while ( $offset < $row_count ) {
			$rows = $wpdb->get_results(
				"SELECT * FROM `{$table}` LIMIT " . self::ROWS_PER_BATCH . " OFFSET {$offset}",
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			if ( $columns === null ) {
				$columns = array_keys( $rows[0] );
			}

			$column_list = '`' . implode( '`, `', $columns ) . '`';
			$value_rows  = array();

			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $columns as $column ) {
					$value = $row[ $column ];
					if ( $value === null ) {
						$values[] = 'NULL';
					} else {
						$values[] = "'" . esc_sql( $value ) . "'";
					}
				}
				$value_rows[] = '(' . implode( ', ', $values ) . ')';
			}

			fwrite(
				$handle,
				"INSERT INTO `{$table}` ({$column_list}) VALUES\n" . implode( ",\n", $value_rows ) . ";\n"
			);

			$offset += self::ROWS_PER_BATCH;
		}

		fwrite( $handle, "\n" );
	}

	/**
	 * Naimportuje SQL dump vytvořený metodou dump(). Volitelně přepíše prefix tabulek,
	 * pokud se zálohovaný web lišil od cílového (obnova na jiném webu).
	 *
	 * @param string $file_path  Cesta k .sql souboru.
	 * @param string $old_prefix Prefix tabulek v záloze.
	 * @param string $new_prefix Prefix tabulek na cílovém webu.
	 * @throws Exception Když se import nepodaří.
	 */
	public static function restore( $file_path, $old_prefix = '', $new_prefix = '' ) {
		global $wpdb;

		$sql = file_get_contents( $file_path );
		if ( $sql === false ) {
			throw new Exception( 'Nelze přečíst SQL soubor: ' . $file_path );
		}

		if ( $old_prefix !== '' && $old_prefix !== $new_prefix ) {
			$sql = preg_replace( '/`' . preg_quote( $old_prefix, '/' ) . '/', '`' . $new_prefix, $sql );
		}

		foreach ( self::split_sql_statements( $sql ) as $statement ) {
			if ( trim( $statement ) === '' ) {
				continue;
			}

			$result = $wpdb->query( $statement );
			if ( $result === false ) {
				throw new Exception( 'Chyba při importu SQL: ' . $wpdb->last_error );
			}
		}

		if ( $old_prefix !== '' && $old_prefix !== $new_prefix ) {
			self::fix_prefix_dependent_data( $old_prefix, $new_prefix );
		}
	}

	/**
	 * Přejmenování tabulek pokryje jen identifikátory (`wp_options` -> `wpx_options`).
	 * WordPress si ale prefix ukládá i do samotných DAT - role a capabilities uživatelů
	 * jsou klíčované jako "{prefix}capabilities" / "{prefix}user_roles". Bez této opravy
	 * by po obnově na web s jiným prefixem nefungovalo přihlášení ani žádná oprávnění.
	 */
	private static function fix_prefix_dependent_data( $old_prefix, $new_prefix ) {
		global $wpdb;

		$options_table  = $new_prefix . 'options';
		$usermeta_table = $new_prefix . 'usermeta';

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$options_table}` SET option_name = %s WHERE option_name = %s",
				$new_prefix . 'user_roles',
				$old_prefix . 'user_roles'
			)
		);

		foreach ( array( 'capabilities', 'user_level' ) as $suffix ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$usermeta_table}` SET meta_key = %s WHERE meta_key = %s",
					$new_prefix . $suffix,
					$old_prefix . $suffix
				)
			);
		}
	}

	/**
	 * Rozdělí blok SQL na jednotlivé příkazy - respektuje řetězce v uvozovkách,
	 * takže středník uvnitř textového obsahu příkaz nepřeruší.
	 */
	private static function split_sql_statements( $sql ) {
		$statements = array();
		$current    = '';
		$in_string  = false;
		$escape_next = false;
		$len        = strlen( $sql );

		for ( $i = 0; $i < $len; $i++ ) {
			$char     = $sql[ $i ];
			$current .= $char;

			if ( $escape_next ) {
				$escape_next = false;
				continue;
			}

			if ( $char === '\\' && $in_string ) {
				$escape_next = true;
				continue;
			}

			if ( $char === "'" ) {
				$in_string = ! $in_string;
				continue;
			}

			if ( $char === ';' && ! $in_string ) {
				$statements[] = trim( $current );
				$current      = '';
			}
		}

		if ( trim( $current ) !== '' ) {
			$statements[] = trim( $current );
		}

		return $statements;
	}

	/**
	 * Nahradí výskyt $from za $to napříč celou databází (jen ve sloupcích textového typu),
	 * bezpečně i uvnitř serializovaných PHP hodnot (opraví délkové prefixy).
	 *
	 * Používá se při obnově zálohy z jiného webu, kdy je potřeba přepsat starou doménu na novou.
	 */
	public static function search_replace( $from, $to ) {
		global $wpdb;

		if ( $from === '' || $from === $to ) {
			return;
		}

		$tables = $wpdb->get_col( 'SHOW TABLES' );

		foreach ( $tables as $table ) {
			$primary_key = self::get_single_primary_key( $table );
			if ( ! $primary_key ) {
				continue; // Bez jednoznačného klíče tabulku bezpečně needitujeme.
			}

			$text_columns = self::get_text_columns( $table );
			if ( empty( $text_columns ) ) {
				continue;
			}

			self::search_replace_table( $table, $primary_key, $text_columns, $from, $to );
		}
	}

	private static function get_single_primary_key( $table ) {
		global $wpdb;

		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );

		if ( count( $keys ) !== 1 ) {
			return null;
		}

		return $keys[0]['Column_name'];
	}

	private static function get_text_columns( $table ) {
		global $wpdb;

		$columns     = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
		$text_types  = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext' );
		$text_columns = array();

		foreach ( $columns as $column ) {
			$type = strtolower( preg_replace( '/\(.*$/', '', $column['Type'] ) );
			if ( in_array( $type, $text_types, true ) ) {
				$text_columns[] = $column['Field'];
			}
		}

		return $text_columns;
	}

	private static function search_replace_table( $table, $primary_key, $columns, $from, $to ) {
		global $wpdb;

		$column_list = '`' . $primary_key . '`, `' . implode( '`, `', $columns ) . '`';
		$offset      = 0;

		while ( true ) {
			$rows = $wpdb->get_results(
				"SELECT {$column_list} FROM `{$table}` LIMIT " . self::ROWS_PER_BATCH . " OFFSET {$offset}",
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$updates = array();

				foreach ( $columns as $column ) {
					$original = $row[ $column ];
					if ( $original === null || strpos( $original, $from ) === false ) {
						continue;
					}

					$replaced = self::recursive_unserialize_replace( $from, $to, $original );
					if ( $replaced !== $original ) {
						$updates[ $column ] = $replaced;
					}
				}

				if ( ! empty( $updates ) ) {
					$wpdb->update( $table, $updates, array( $primary_key => $row[ $primary_key ] ) );
				}
			}

			$offset += self::ROWS_PER_BATCH;
		}
	}

	/**
	 * Rekurzivně projde (i serializovanou) hodnotu a nahradí $from za $to,
	 * u serializovaných dat po cestě opraví délkové prefixy.
	 */
	private static function recursive_unserialize_replace( $from, $to, $data ) {
		if ( is_string( $data ) ) {
			$unserialized = @unserialize( $data );
			if ( $unserialized !== false || $data === serialize( false ) ) {
				return serialize( self::recursive_unserialize_replace( $from, $to, $unserialized ) );
			}

			return str_replace( $from, $to, $data );
		}

		if ( is_array( $data ) ) {
			$result = array();
			foreach ( $data as $key => $value ) {
				$result[ $key ] = self::recursive_unserialize_replace( $from, $to, $value );
			}
			return $result;
		}

		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$data->$key = self::recursive_unserialize_replace( $from, $to, $value );
			}
			return $data;
		}

		return $data;
	}
}
