<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hlavní logika zálohování a obnovy: DB dump/import + zip/rozbalení wp-content (bez uploads).
 * Zálohy jsou pojmenované podle data a času pořízení, udržují se 2 nejnovější,
 * každý běh tedy přepíše (smaže) tu nejstarší.
 */
class Velrion_Backup_Core {

	/** Adresáře v wp-content, které se do zálohy nezahrnují ani při obnově nepřepisují. */
	private static $excluded_dirs = array( 'uploads', 'cache', 'upgrade' );

	/** Kolik posledních záloh se má uchovávat. */
	const KEEP_BACKUPS = 2;

	/** Předpona a přípona souboru zálohy, mezi nimi je časové razítko ve formátu Y-m-d-His. */
	const FILE_PREFIX = 'velrion-backup-';
	const FILE_SUFFIX = '.zip';

	/**
	 * Regulární výraz časového razítka v názvu zálohy. Povoluje i starší formát bez času
	 * (Y-m-d), aby zůstaly obnovitelné zálohy vytvořené předchozími verzemi pluginu.
	 */
	const FILE_STAMP_PATTERN = '\\d{4}-\\d{2}-\\d{2}(?:-\\d{6})?';

	public static function get_settings() {
		$defaults = array(
			'enabled'          => true,
			'weekday'          => 0, // 0 = neděle ... 6 = sobota (dle date('w'))
			'hour'             => 3, // výchozí běh mezi 3:00 a 4:00 ráno
			'backup_dir'       => self::default_backup_dir(),
			'email_on_failure' => true,
		);

		$saved = get_option( VELRION_BACKUP_OPTION, array() );

		return wp_parse_args( $saved, $defaults );
	}

	public static function default_backup_dir() {
		$suffix = get_option( 'velrion_backup_dir_suffix' );
		if ( ! $suffix ) {
			$suffix = substr( wp_generate_password( 12, false, false ), 0, 8 );
			update_option( 'velrion_backup_dir_suffix', $suffix );
		}

		return dirname( untrailingslashit( ABSPATH ) ) . '/velrion-backups-' . $suffix;
	}

	public static function get_state() {
		return get_option(
			VELRION_BACKUP_STATE_OPTION,
			array(
				'last_run'             => null,
				'status'               => null, // 'success' | 'error'
				'message'              => '',
				'size'                 => 0,
				'duration'             => 0,
				'last_file'            => '',
				'last_restore'         => null,
				'last_restore_source'  => '',
				'last_restore_status'  => null,
				'last_restore_message' => '',
			)
		);
	}

	private static function set_state( $state ) {
		update_option( VELRION_BACKUP_STATE_OPTION, $state, false );
	}

	/**
	 * Najde existující záložní ZIP soubory v adresáři, seřazené od nejnovější.
	 */
	private static function find_existing_backups( $backup_dir ) {
		$files = glob( trailingslashit( $backup_dir ) . self::FILE_PREFIX . '*' . self::FILE_SUFFIX );
		if ( ! $files ) {
			return array();
		}

		$backups = array();
		foreach ( $files as $path ) {
			$backups[] = array(
				'path'  => $path,
				'mtime' => filemtime( $path ),
				'size'  => filesize( $path ),
			);
		}

		usort(
			$backups,
			function ( $a, $b ) {
				return $b['mtime'] - $a['mtime'];
			}
		);

		return $backups;
	}

	/**
	 * Vrátí seznam záloh pro zobrazení v adminu (doplněný o prázdné sloty, pokud jich je málo).
	 */
	public static function list_backups( $settings ) {
		$backups = array();

		foreach ( self::find_existing_backups( $settings['backup_dir'] ) as $backup ) {
			$backups[] = array(
				'path'   => $backup['path'],
				'exists' => true,
				'mtime'  => $backup['mtime'],
				'size'   => $backup['size'],
			);
		}

		while ( count( $backups ) < self::KEEP_BACKUPS ) {
			$backups[] = array(
				'path'   => '',
				'exists' => false,
				'mtime'  => null,
				'size'   => 0,
			);
		}

		return $backups;
	}

	/**
	 * Ponechá jen $keep nejnovějších záloh, zbytek smaže.
	 *
	 * @param string   $backup_dir Adresář se zálohami.
	 * @param int|null $keep       Kolik nejnovějších ponechat (výchozí self::KEEP_BACKUPS).
	 */
	private static function prune_old_backups( $backup_dir, $keep = null ) {
		$keep    = null === $keep ? self::KEEP_BACKUPS : max( 0, (int) $keep );
		$backups = self::find_existing_backups( $backup_dir );

		foreach ( array_slice( $backups, $keep ) as $old ) {
			@unlink( $old['path'] );
		}
	}

	/**
	 * Odhadne, jestli je na disku místo pro další archiv - podle velikosti té nejnovější
	 * existující zálohy. Bez téhle kontroly se nedostatek místa projeví až selháním zápisu
	 * po několika minutách stavby archivu.
	 *
	 * @throws Exception Když volného místa zjevně nestačí.
	 */
	private static function assert_enough_space( $backup_dir ) {
		$backups = self::find_existing_backups( $backup_dir );
		if ( ! $backups ) {
			return; // První běh - není podle čeho odhadovat.
		}

		$needed = $backups[0]['size'];
		$free   = @disk_free_space( $backup_dir );

		if ( false === $free || $free >= $needed ) {
			return;
		}

		throw new Exception(
			sprintf(
				'Na disku není dost místa pro novou zálohu: volno %s, poslední záloha má %s.',
				size_format( $free ),
				size_format( $needed )
			)
		);
	}

	/**
	 * Spustí zálohování. Volá se z cronu i z ručního tlačítka.
	 *
	 * @return string 'success' | 'error' | 'locked' (jiný běh právě probíhá, nic se nedělo).
	 */
	public static function run() {
		if ( get_transient( VELRION_BACKUP_LOCK_KEY ) ) {
			return 'locked';
		}
		set_transient( VELRION_BACKUP_LOCK_KEY, 1, 15 * MINUTE_IN_SECONDS );

		@set_time_limit( 0 );
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		$settings = self::get_settings();
		$started  = microtime( true );
		$sql_tmp  = '';
		$zip_tmp  = '';

		try {
			$backup_dir = self::prepare_backup_dir( $settings['backup_dir'] );

			// Nejstarší slot uvolníme JEŠTĚ PŘED stavbou nového archivu. Zálohy mají stovky MB
			// a při pořadí "napřed nový, pak úklid" musí být na disku místo pro KEEP_BACKUPS + 1
			// archivů zároveň; na běžném hostingu to došlo a zápis ZIPu tiše selhal. Nejnovější
			// záloha zůstává nedotčená, takže i když tenhle běh selže, pořád je z čeho obnovit.
			self::prune_old_backups( $backup_dir, self::KEEP_BACKUPS - 1 );
			self::assert_enough_space( $backup_dir );

			// Každý běh zakládá nový soubor s časovým razítkem (včetně hodin/minut/sekund),
			// takže se nikdy nepřepíše poslední záloha.
			$stamp    = current_time( 'Y-m-d-His' );
			$zip_path = trailingslashit( $backup_dir ) . self::FILE_PREFIX . $stamp . self::FILE_SUFFIX;
			$sql_tmp  = trailingslashit( $backup_dir ) . 'database.sql';
			$zip_tmp  = $zip_path . '.tmp';

			Velrion_Backup_DB::dump( $sql_tmp );
			self::build_zip( $zip_tmp, $sql_tmp );

			@unlink( $sql_tmp );

			// Pojistka pro nepravděpodobný případ dvou běhů ve stejnou sekundu.
			if ( file_exists( $zip_path ) ) {
				@unlink( $zip_path );
			}
			if ( ! rename( $zip_tmp, $zip_path ) ) {
				throw new Exception( 'Hotový archiv se nepodařilo přesunout na místo: ' . $zip_path );
			}

			self::prune_old_backups( $backup_dir );

			// Bez téhle kontroly by se běh, ve kterém archiv nevznikl, zapsal jako úspěšný
			// s nulovou velikostí - admin pak hlásil "OK" a žádný nový soubor v seznamu.
			$size = file_exists( $zip_path ) ? filesize( $zip_path ) : 0;
			if ( ! $size ) {
				throw new Exception( 'Záloha skončila bez použitelného archivu: ' . basename( $zip_path ) );
			}

			self::set_state(
				array_merge(
					self::get_state(),
					array(
						'last_run'  => time(),
						'status'    => 'success',
						'message'   => '',
						'size'      => $size,
						'duration'  => round( microtime( true ) - $started, 1 ),
						'last_file' => basename( $zip_path ),
					)
				)
			);

			$result = 'success';
		} catch ( Exception $e ) {
			// Neúspěšný běh po sobě nechává rozpracovaný dump a .tmp archiv. Ty neodpovídají
			// masce záloh, takže by je prune_old_backups() nikdy neuklidilo a postupně by
			// zaplnily disk - a další zálohy by pak selhávaly na nedostatku místa.
			if ( ! empty( $sql_tmp ) ) {
				@unlink( $sql_tmp );
			}
			if ( ! empty( $zip_tmp ) ) {
				@unlink( $zip_tmp );
			}

			self::set_state(
				array_merge(
					self::get_state(),
					array(
						'last_run' => time(),
						'status'   => 'error',
						'message'  => $e->getMessage(),
						'size'     => 0,
						'duration' => round( microtime( true ) - $started, 1 ),
					)
				)
			);

			if ( ! empty( $settings['email_on_failure'] ) ) {
				wp_mail(
					get_option( 'admin_email' ),
					'[' . get_bloginfo( 'name' ) . '] Záloha selhala',
					"Automatická záloha webu selhala.\n\nChyba: " . $e->getMessage() . "\nČas: " . gmdate( 'Y-m-d H:i:s' ) . " UTC"
				);
			}

			$result = 'error';
		}

		delete_transient( VELRION_BACKUP_LOCK_KEY );

		return $result;
	}

	/**
	 * Ověří, že zadaný název souboru je jedna z existujících lokálních záloh
	 * uvnitř nastaveného záložního adresáře (ochrana proti path traversal).
	 *
	 * @throws Exception Když soubor neodpovídá.
	 */
	public static function resolve_local_backup_path( $filename ) {
		$settings = self::get_settings();
		$dir      = realpath( $settings['backup_dir'] );

		if ( ! $dir ) {
			throw new Exception( 'Záložní adresář neexistuje.' );
		}

		$pattern = '/^' . preg_quote( self::FILE_PREFIX, '/' ) . self::FILE_STAMP_PATTERN . preg_quote( self::FILE_SUFFIX, '/' ) . '$/';
		if ( ! preg_match( $pattern, basename( $filename ) ) ) {
			throw new Exception( 'Neplatný název souboru zálohy.' );
		}

		$candidate = trailingslashit( $dir ) . basename( $filename );
		$real      = realpath( $candidate );

		if ( ! $real || strpos( $real, $dir ) !== 0 ) {
			throw new Exception( 'Zálohu se nepodařilo najít.' );
		}

		return $real;
	}

	/**
	 * Obnoví web (DB + wp-content bez uploads) ze zadaného ZIP souboru.
	 * Funguje jak pro lokální zálohu tohoto webu, tak pro ZIP nahraný z jiného webu -
	 * v tom případě podle site-meta.json v záloze přepíše prefix tabulek a doménu.
	 *
	 * @param string $zip_path     Cesta k ZIP souboru se zálohou.
	 * @param string $source_label Popisek zdroje pro zobrazení stavu (jméno souboru).
	 * @throws Exception Když obnova selže.
	 */
	public static function restore( $zip_path, $source_label ) {
		if ( get_transient( VELRION_BACKUP_LOCK_KEY ) ) {
			throw new Exception( 'Právě probíhá jiná záloha nebo obnova, zkuste to prosím za chvíli znovu.' );
		}
		set_transient( VELRION_BACKUP_LOCK_KEY, 1, 30 * MINUTE_IN_SECONDS );

		@set_time_limit( 0 );
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		global $wpdb;

		$staging = '';

		try {
			$settings   = self::get_settings();
			$backup_dir = self::prepare_backup_dir( $settings['backup_dir'] );
			$staging    = trailingslashit( $backup_dir ) . 'restore-staging-' . substr( wp_generate_password( 12, false, false ), 0, 8 ) . '/';

			// Zálohovaný dump obsahuje CELOU tabulku wp_options - včetně vlastního nastavení
			// tohoto pluginu a taky siteurl/home. Bez uložení a zpětného obnovení by import cizí
			// databáze (jiný web) přepsal cestu k záložnímu adresáři i doménu webu hodnotami ze
			// zdrojového webu. Proto si lokální hodnoty uchováme stranou ještě před importem.
			$local_settings   = get_option( VELRION_BACKUP_OPTION );
			$local_dir_suffix = get_option( 'velrion_backup_dir_suffix' );
			$target_home      = untrailingslashit( home_url() );
			$target_site      = untrailingslashit( site_url() );

			if ( ! class_exists( 'ZipArchive' ) ) {
				throw new Exception( 'PHP rozšíření ZipArchive není dostupné.' );
			}

			$zip = new ZipArchive();
			if ( $zip->open( $zip_path ) !== true ) {
				throw new Exception( 'Nepodařilo se otevřít ZIP soubor.' );
			}

			if ( $zip->locateName( 'database.sql' ) === false ) {
				$zip->close();
				throw new Exception( 'ZIP neobsahuje database.sql - nejde o zálohu vytvořenou tímto pluginem.' );
			}

			wp_mkdir_p( $staging );
			$zip->extractTo( $staging );
			$zip->close();

			$meta = array();
			if ( file_exists( $staging . 'site-meta.json' ) ) {
				$decoded = json_decode( (string) file_get_contents( $staging . 'site-meta.json' ), true );
				if ( is_array( $decoded ) ) {
					$meta = $decoded;
				}
			}

			$old_prefix = ! empty( $meta['table_prefix'] ) ? $meta['table_prefix'] : $wpdb->prefix;

			Velrion_Backup_DB::restore( $staging . 'database.sql', $old_prefix, $wpdb->prefix );

			// Přímé SQL příkazy obešly cache WordPress Options API - je potřeba ji zahodit,
			// jinak update_option() níže porovná s (už neplatnou) hodnotou z cache a zápis
			// vyhodnotí jako zbytečný "beze změny", takže by v DB zůstala hodnota ze zálohy.
			wp_cache_flush();

			// Vrátit lokální hodnoty zpět (viz poznámka výše): nastavení pluginu, cestu k
			// zálohám, doménu webu (siteurl/home) a podle nastavení přeplánovat cron.
			if ( $local_settings !== false ) {
				update_option( VELRION_BACKUP_OPTION, $local_settings );
			}
			if ( $local_dir_suffix !== false ) {
				update_option( 'velrion_backup_dir_suffix', $local_dir_suffix );
			}
			update_option( 'siteurl', $target_site );
			update_option( 'home', $target_home );

			$restored_settings = self::get_settings();
			if ( ! empty( $restored_settings['enabled'] ) ) {
				Velrion_Backup_Cron::schedule( (int) $restored_settings['weekday'], (int) $restored_settings['hour'] );
			} else {
				Velrion_Backup_Cron::unschedule();
			}

			$old_home = ! empty( $meta['home_url'] ) ? untrailingslashit( $meta['home_url'] ) : null;
			$old_site = ! empty( $meta['site_url'] ) ? untrailingslashit( $meta['site_url'] ) : null;
			$new_home = $target_home;
			$new_site = $target_site;

			if ( $old_home && $old_home !== $new_home ) {
				Velrion_Backup_DB::search_replace( $old_home, $new_home );
			}
			if ( $old_site && $old_site !== $old_home && $old_site !== $new_site ) {
				Velrion_Backup_DB::search_replace( $old_site, $new_site );
			}

			if ( is_dir( $staging . 'wp-content' ) ) {
				self::copy_dir( $staging . 'wp-content', WP_CONTENT_DIR, self::$excluded_dirs );
			}

			self::remove_dir( $staging );

			self::set_state(
				array_merge(
					self::get_state(),
					array(
						'last_restore'         => time(),
						'last_restore_source'  => $source_label,
						'last_restore_status'  => 'success',
						'last_restore_message' => '',
					)
				)
			);
		} catch ( Exception $e ) {
			self::remove_dir( $staging );

			self::set_state(
				array_merge(
					self::get_state(),
					array(
						'last_restore'         => time(),
						'last_restore_source'  => $source_label,
						'last_restore_status'  => 'error',
						'last_restore_message' => $e->getMessage(),
					)
				)
			);

			delete_transient( VELRION_BACKUP_LOCK_KEY );
			throw $e;
		}

		delete_transient( VELRION_BACKUP_LOCK_KEY );
	}

	/**
	 * Zkopíruje obsah $from do $to, kromě vyloučených podadresářů první úrovně.
	 */
	private static function copy_dir( $from, $to, $exclude_top_dirs = array() ) {
		$from = untrailingslashit( $from );
		$to   = untrailingslashit( $to );

		if ( ! is_dir( $from ) ) {
			return;
		}

		$base_len = strlen( trailingslashit( $from ) );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$relative = substr( $item->getPathname(), $base_len );
			$top_dir  = strtok( $relative, '/' );

			if ( in_array( $top_dir, $exclude_top_dirs, true ) ) {
				continue;
			}

			$target = $to . '/' . str_replace( '\\', '/', $relative );

			if ( $item->isDir() ) {
				wp_mkdir_p( $target );
			} else {
				wp_mkdir_p( dirname( $target ) );
				copy( $item->getPathname(), $target );
			}
		}
	}

	/**
	 * Rekurzivně smaže adresář (používá se pro úklid dočasného rozbalení při obnově).
	 */
	private static function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}

		@rmdir( $dir );
	}

	/**
	 * Zajistí existenci a ochranu záložního adresáře mimo web-root (nebo tam, kam si ho uživatel nastavil).
	 */
	private static function prepare_backup_dir( $dir ) {
		if ( ! file_exists( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				throw new Exception( 'Nepodařilo se vytvořit záložní adresář: ' . $dir );
			}
		}

		if ( ! is_writable( $dir ) ) {
			throw new Exception( 'Záložní adresář není zapisovatelný: ' . $dir );
		}

		// Ochrana pro případ, že adresář přesto skončí v dosahu webserveru.
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return $dir;
	}

	/**
	 * Vytvoří ZIP obsahující database.sql, site-meta.json a wp-content bez vyloučených adresářů.
	 */
	private static function build_zip( $zip_path, $sql_file ) {
		global $wpdb;

		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new Exception( 'PHP rozšíření ZipArchive není dostupné.' );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			throw new Exception( 'Nepodařilo se vytvořit ZIP soubor: ' . $zip_path );
		}

		$zip->addFile( $sql_file, 'database.sql' );

		$meta = array(
			'plugin'         => 'Velrion Backup',
			'plugin_version' => VELRION_BACKUP_VERSION,
			'site_name'      => get_bloginfo( 'name' ),
			'home_url'       => home_url(),
			'site_url'       => site_url(),
			'table_prefix'   => $wpdb->prefix,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'backup_date'    => current_time( 'mysql' ),
		);
		$zip->addFromString( 'site-meta.json', wp_json_encode( $meta, JSON_PRETTY_PRINT ) );

		$content_dir = untrailingslashit( WP_CONTENT_DIR );
		$base_len    = strlen( trailingslashit( $content_dir ) );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $content_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$real_path = $item->getPathname();

			if ( $item->isLink() ) {
				continue;
			}

			$relative = substr( $real_path, $base_len );
			$top_dir  = strtok( $relative, '/' );

			if ( in_array( $top_dir, self::$excluded_dirs, true ) ) {
				continue;
			}

			$zip_entry = 'wp-content/' . str_replace( '\\', '/', $relative );

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $zip_entry );
			} else {
				$zip->addFile( $real_path, $zip_entry );
			}
		}

		// ZipArchive zapisuje obsah archivu až při close(). Když zápis selže (nejčastěji plný
		// disk nebo kvóta), close() jen vrátí false a vyhodí warning - bez téhle kontroly se
		// build_zip() vrátí jako by bylo hotovo a soubor přitom vůbec nevznikne.
		if ( ! $zip->close() ) {
			throw new Exception( 'Zápis ZIP archivu selhal (typicky nedostatek místa na disku): ' . $zip_path );
		}

		if ( ! file_exists( $zip_path ) || ! filesize( $zip_path ) ) {
			throw new Exception( 'ZIP archiv se nepodařilo vytvořit: ' . $zip_path );
		}
	}
}
