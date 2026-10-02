<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jádro pluginu: vytvoření, ověření a obnova zálohy + rozhodnutí, kdy je čas na
 * automatickou zálohu.
 *
 * Plánování nespoléhá na přesný okamžik spuštění. Cron (systémový nebo WP-Cron) jen
 * jednou za hodinu zavolá tick() a ten se podívá, jestli od posledního plánovaného
 * termínu záloha proběhla. Když se nějaké spuštění cronu vynechá, záloha se dožene
 * při dalším.
 *
 * Formát ZIPu: database.sql, velrion-backup.json (metadata) a files/ (obsah web-rootu).
 */
class Velrion_Backup {

	const KEEP         = 3;
	const FILE_PREFIX  = 'velrion-backup-';
	const OPT_SETTINGS = 'velrion_backup_settings';
	const OPT_STATE    = 'velrion_backup_state';
	const OPT_DIR      = 'velrion_backup_dir';
	const OPT_KEY      = 'velrion_backup_cron_key';
	const OPT_PING     = 'velrion_backup_ping';
	const OPT_LOCK     = 'velrion_backup_lock';
	const CRON_HOOK    = 'velrion_backup_tick';

	/** Zámek starší než tohle je po spadlém běhu a smí se převzít. */
	const LOCK_TTL = 7200;

	/** Kolikrát zkusit automatickou zálohu jednoho termínu, když selže. */
	const MAX_ATTEMPTS = 3;

	/** Soubory, které už jsou komprimované - zbytečně by zdržovaly. */
	private static $stored_ext = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'zip', 'gz', 'rar', '7z', 'mp4', 'mov', 'mp3', 'woff', 'woff2' );

	/* ------------------------------------------------------------------ nastavení */

	public static function settings() {
		return wp_parse_args(
			get_option( self::OPT_SETTINGS, array() ),
			array(
				'enabled'          => false,
				'frequency'        => 'weekly', // daily | weekly
				'weekday'          => 0,        // 0 = neděle ... 6 = sobota
				'hour'             => 3,
				'email_on_failure' => true,
			)
		);
	}

	public static function state() {
		return wp_parse_args(
			get_option( self::OPT_STATE, array() ),
			array(
				'last_run'       => 0,
				'status'         => '',   // success | error
				'message'        => '',
				'last_file'      => '',
				'size'           => 0,
				'duration'       => 0,
				'trigger'        => '',   // manual | auto
				'enabled_at'     => 0,
				'auto_slot'      => 0,    // poslední termín, který je vyřízený
				'attempt_slot'   => 0,
				'attempts'       => 0,
				'restore_time'   => 0,
				'restore_file'   => '',
				'restore_status' => '',
				'restore_msg'    => '',
			)
		);
	}

	public static function update_state( $changes ) {
		update_option( self::OPT_STATE, array_merge( self::state(), $changes ), false );
	}

	public static function cron_key() {
		$key = get_option( self::OPT_KEY );
		if ( ! $key ) {
			$key = wp_generate_password( 32, false, false );
			update_option( self::OPT_KEY, $key, false );
		}
		return $key;
	}

	public static function cron_url() {
		return add_query_arg(
			array(
				'action' => 'velrion_backup_cron',
				'key'    => self::cron_key(),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/* ------------------------------------------------------------------ adresář záloh */

	/**
	 * Adresář pro zálohy. Najde se automaticky - přednostně nad web-rootem (mimo dosah URL),
	 * když tam hosting nedovolí zapisovat, tak ve wp-content. Jednou zvolený adresář se
	 * pamatuje; když přestane existovat (přesun webu na jiný hosting), hledá se znovu.
	 * Vlastní umístění jde vynutit konstantou VELRION_BACKUP_DIR ve wp-config.php.
	 */
	public static function dir() {
		if ( defined( 'VELRION_BACKUP_DIR' ) && VELRION_BACKUP_DIR ) {
			return untrailingslashit( VELRION_BACKUP_DIR );
		}

		$saved = get_option( self::OPT_DIR );
		if ( $saved && @is_dir( $saved ) && @is_writable( $saved ) ) {
			return $saved;
		}

		// Starší verze ukládaly náhodnou příponu zvlášť - použijeme ji, ať se adresář nemění.
		$suffix = get_option( 'velrion_backup_dir_suffix' );
		if ( ! $suffix ) {
			$suffix = strtolower( wp_generate_password( 8, false, false ) );
		}
		$name = 'velrion-backups-' . $suffix;

		$candidates = array(
			dirname( untrailingslashit( ABSPATH ) ) . '/' . $name,
			untrailingslashit( WP_CONTENT_DIR ) . '/' . $name,
		);

		foreach ( $candidates as $dir ) {
			// @ - mimo open_basedir PHP vyhazuje warningy.
			$ok = @is_dir( $dir ) ? @is_writable( $dir ) : ( @is_writable( dirname( $dir ) ) && @wp_mkdir_p( $dir ) );
			if ( $ok ) {
				update_option( self::OPT_DIR, $dir, false );
				return $dir;
			}
		}

		return $candidates[0];
	}

	public static function is_dir_public( $dir ) {
		$dir   = trailingslashit( wp_normalize_path( $dir ) );
		$roots = array( ABSPATH );
		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$roots[] = $_SERVER['DOCUMENT_ROOT'];
		}
		foreach ( $roots as $root ) {
			if ( 0 === strpos( $dir, trailingslashit( wp_normalize_path( $root ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @throws Exception
	 */
	private static function prepare_dir() {
		$dir = self::dir();

		if ( ! @is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new Exception( 'Nepodařilo se vytvořit adresář pro zálohy: ' . $dir );
		}
		if ( ! @is_writable( $dir ) ) {
			throw new Exception( 'Do adresáře pro zálohy nejde zapisovat: ' . $dir );
		}

		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		return $dir;
	}

	/* ------------------------------------------------------------------ seznam záloh */

	/**
	 * Všechny ZIPy v adresáři záloh, od nejnovějšího. Patří sem i ZIPy nahrané ručně
	 * (např. přes FTP), ty se ale automaticky nemažou.
	 */
	public static function backups() {
		$list = array();

		foreach ( (array) glob( self::dir() . '/*.zip' ) as $path ) {
			if ( ! $path || ! is_file( $path ) ) {
				continue;
			}
			$list[] = array(
				'file'  => basename( $path ),
				'path'  => $path,
				'time'  => filemtime( $path ),
				'size'  => filesize( $path ),
				'own'   => 0 === strpos( basename( $path ), self::FILE_PREFIX ),
				'info'  => self::read_info( $path ),
			);
		}

		usort(
			$list,
			function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);

		return $list;
	}

	/**
	 * Bezpečně převede jméno souboru z formuláře na cestu v adresáři záloh.
	 *
	 * @throws Exception
	 */
	public static function path_for( $file ) {
		$file = basename( (string) $file );
		$path = self::dir() . '/' . $file;

		if ( ! preg_match( '/^[A-Za-z0-9._-]+\.zip$/', $file ) || ! is_file( $path ) ) {
			throw new Exception( 'Záloha nebyla nalezena.' );
		}

		return $path;
	}

	/** Metadata zálohy ve vedlejším souboru <zip>.json (kontrolní součet, výsledek ověření). */
	public static function read_info( $zip_path ) {
		$data = is_file( $zip_path . '.json' ) ? json_decode( (string) file_get_contents( $zip_path . '.json' ), true ) : null;
		return is_array( $data ) ? $data : array();
	}

	private static function write_info( $zip_path, $changes ) {
		@file_put_contents( $zip_path . '.json', wp_json_encode( array_merge( self::read_info( $zip_path ), $changes ), JSON_PRETTY_PRINT ) );
	}

	public static function delete( $zip_path ) {
		@unlink( $zip_path );
		@unlink( $zip_path . '.json' );
	}

	/** Ponechá $keep nejnovějších záloh vytvořených pluginem, starší smaže. */
	private static function prune( $keep ) {
		$own = array_values(
			array_filter(
				self::backups(),
				function ( $b ) {
					return $b['own'];
				}
			)
		);

		foreach ( array_slice( $own, max( 0, $keep ) ) as $old ) {
			self::delete( $old['path'] );
		}
	}

	/* ------------------------------------------------------------------ zámek */

	/**
	 * Zámek proti souběhu (dvě zálohy, záloha + obnova). Přímý INSERT IGNORE nad unikátním
	 * option_name je atomický - add_option() by nebylo (používá ON DUPLICATE KEY UPDATE).
	 */
	private static function lock() {
		global $wpdb;

		$now = time();
		if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPT_LOCK, $now ) ) ) {
			return true;
		}

		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPT_LOCK ) );
		if ( $held > $now - self::LOCK_TTL ) {
			return false;
		}

		// Převzetí zámku po spadlém běhu - jen pokud ho mezitím nepřevzal někdo jiný.
		return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $now, self::OPT_LOCK, $held ) );
	}

	private static function unlock() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::OPT_LOCK ) );
	}

	public static function is_running() {
		global $wpdb;
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPT_LOCK ) );
		return $held > time() - self::LOCK_TTL;
	}

	private static function prepare_long_run() {
		@set_time_limit( 0 );
		ignore_user_abort( true );
		wp_raise_memory_limit( 'admin' );
	}

	/* ------------------------------------------------------------------ záloha */

	/**
	 * Vytvoří kompletní zálohu (databáze + všechny soubory webu) a hned ji ověří.
	 *
	 * @param string $trigger manual | auto
	 * @return array{ok:bool,message:string}
	 */
	public static function create( $trigger = 'manual' ) {
		if ( ! self::lock() ) {
			return array(
				'ok'      => false,
				'message' => 'Právě probíhá jiná záloha nebo obnova.',
			);
		}

		self::prepare_long_run();

		$started = microtime( true );
		$sql     = '';
		$tmp     = '';

		try {
			if ( ! class_exists( 'ZipArchive' ) ) {
				throw new Exception( 'Na serveru chybí PHP rozšíření ZipArchive.' );
			}

			$dir = self::prepare_dir();

			// Pozůstatky přerušených běhů (PHP proces zabitý hostingem): rozpracovaný export
			// a dočasné soubory ZIPu. Běží pod zámkem, takže nic jiného je právě nepoužívá.
			foreach ( array_merge( (array) glob( $dir . '/*.tmp*' ), array( $dir . '/database.sql' ) ) as $leftover ) {
				if ( $leftover && is_file( $leftover ) ) {
					@unlink( $leftover );
				}
			}

			// Nejstarší zálohu smažeme ještě před vytvořením nové, aby na disku stačilo místo
			// na KEEP archivů (ne KEEP + 1).
			self::prune( self::KEEP - 1 );

			$name = self::FILE_PREFIX . current_time( 'Y-m-d-His' ) . '.zip';
			$path = $dir . '/' . $name;
			$tmp  = $path . '.tmp';
			$sql  = $dir . '/database.sql';

			Velrion_Backup_DB::dump( $sql );
			$result = self::build_zip( $tmp, $sql, $dir );
			@unlink( $sql );

			if ( ! @rename( $tmp, $path ) ) {
				throw new Exception( 'Hotový archiv se nepodařilo uložit: ' . $path );
			}

			$check = self::verify( $path );
			if ( ! $check['ok'] ) {
				self::delete( $path );
				throw new Exception( 'Nová záloha neprošla kontrolou integrity: ' . $check['message'] );
			}

			$size    = filesize( $path );
			$message = $result['skipped'] ? count( $result['skipped'] ) . ' nečitelných nebo během zálohy smazaných souborů vynecháno (např. ' . implode( ', ', array_slice( $result['skipped'], 0, 3 ) ) . ').' : '';

			self::write_info(
				$path,
				array(
					'created' => time(),
					'trigger' => $trigger,
					'files'   => $result['files'],
					'skipped' => array_slice( $result['skipped'], 0, 50 ),
				)
			);

			self::update_state(
				array(
					'last_run'  => time(),
					'status'    => 'success',
					'message'   => $message,
					'last_file' => $name,
					'size'      => $size,
					'duration'  => round( microtime( true ) - $started ),
					'trigger'   => $trigger,
				)
			);

			$response = array(
				'ok'      => true,
				'message' => 'Záloha ' . $name . ' vytvořena a ověřena (' . size_format( $size ) . ').',
			);
		} catch ( Exception $e ) {
			if ( $sql ) {
				@unlink( $sql );
			}
			if ( $tmp ) {
				@unlink( $tmp );
			}

			self::update_state(
				array(
					'last_run' => time(),
					'status'   => 'error',
					'message'  => $e->getMessage(),
					'size'     => 0,
					'duration' => round( microtime( true ) - $started ),
					'trigger'  => $trigger,
				)
			);

			if ( 'auto' === $trigger && self::settings()['email_on_failure'] ) {
				wp_mail(
					get_option( 'admin_email' ),
					'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Automatická záloha selhala',
					"Automatická záloha webu " . home_url() . " selhala.\n\nChyba: " . $e->getMessage() . "\n\nPodrobnosti: " . admin_url( 'options-general.php?page=velrion-backup' )
				);
			}

			$response = array(
				'ok'      => false,
				'message' => 'Záloha selhala: ' . $e->getMessage(),
			);
		}

		self::unlock();

		return $response;
	}

	/**
	 * Adresáře (relativně k web-rootu), jejichž obsah se během zálohy mění nebo maže -
	 * cache a dočasné soubory. Nemají cenu a zmizelý soubor by shodil zápis archivu.
	 */
	private static $volatile_dirs = array(
		'wp-content/cache',
		'wp-content/upgrade',
		'wp-content/upgrade-temp-backup',
		'wp-content/et-cache',
		'wp-content/litespeed',
		'wp-content/wflogs',
		'wp-content/uploads/cache',
		'wp-content/uploads/siteground-optimizer-assets',
		'wp-content/uploads/wpforms/tmp',
		'wp-content/uploads/wc-logs',
		'wp-content/uploads/elementor/css',
	);

	/**
	 * Seznam souborů webu k zálohování: relativní cesta => absolutní cesta.
	 *
	 * @return array{files:array<string,string>,skipped:string[],bytes:int}
	 */
	private static function collect_files( $backup_dir ) {
		$root     = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$base_len = strlen( $root ) + 1;
		$excluded = array( wp_normalize_path( $backup_dir ), wp_normalize_path( (string) realpath( $backup_dir ) ) );
		foreach ( self::$volatile_dirs as $dir ) {
			$excluded[] = $root . '/' . $dir;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				function ( $item ) use ( $excluded ) {
					if ( $item->isLink() ) {
						return false;
					}
					if ( $item->isDir() ) {
						// Adresáře záloh (i ze starších verzí pluginu) nezálohujeme do sebe.
						return ! in_array( wp_normalize_path( $item->getPathname() ), $excluded, true ) && 0 !== strpos( $item->getFilename(), 'velrion-backups-' );
					}
					return ! preg_match( '/^velrion-backup-.*\.zip(\.tmp.*|\.json)?$/', $item->getFilename() );
				}
			),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD // nečitelný adresář přeskočit, ne spadnout
		);

		$files   = array();
		$skipped = array();
		$bytes   = 0;

		foreach ( $iterator as $item ) {
			$path     = wp_normalize_path( $item->getPathname() );
			$relative = substr( $path, $base_len );

			if ( ! $item->isFile() || ! is_readable( $path ) ) {
				$skipped[] = $relative;
				continue;
			}

			$files[ $relative ] = $path;
			$bytes             += $item->getSize();
		}

		return array(
			'files'   => $files,
			'skipped' => $skipped,
			'bytes'   => $bytes,
		);
	}

	/**
	 * Zapíše ZIP. ZipArchive čte soubory až při close() - když mezitím nějaký zmizí
	 * (cache, dočasné soubory pluginů), selže celý archiv. Takové soubory pak vyřadíme
	 * a zápis zopakujeme.
	 *
	 * @throws Exception
	 * @return array{files:int,skipped:string[]}
	 */
	private static function build_zip( $zip_path, $sql_file, $backup_dir ) {
		global $wpdb;

		$list    = self::collect_files( $backup_dir );
		$files   = $list['files'];
		$skipped = $list['skipped'];

		for ( $attempt = 1; ; $attempt++ ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				throw new Exception( 'Nepodařilo se vytvořit ZIP soubor: ' . $zip_path );
			}

			$zip->addFile( $sql_file, 'database.sql' );

			foreach ( $files as $relative => $path ) {
				$entry = 'files/' . $relative;
				$zip->addFile( $path, $entry );
				if ( in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), self::$stored_ext, true ) ) {
					$zip->setCompressionName( $entry, ZipArchive::CM_STORE );
				}
			}

			$zip->addFromString(
				'velrion-backup.json',
				wp_json_encode(
					array(
						'plugin'         => 'Velrion Backup',
						'plugin_version' => VELRION_BACKUP_VERSION,
						'created'        => current_time( 'mysql' ),
						'home_url'       => home_url(),
						'site_url'       => site_url(),
						'table_prefix'   => $wpdb->prefix,
						'wp_version'     => get_bloginfo( 'version' ),
						'files'          => count( $files ),
					),
					JSON_PRETTY_PRINT
				)
			);

			if ( @$zip->close() ) {
				return array(
					'files'   => count( $files ),
					'skipped' => $skipped,
				);
			}

			$error    = $zip->getStatusString();
			$vanished = array_keys(
				array_filter(
					$files,
					function ( $path ) {
						return ! is_readable( $path );
					}
				)
			);

			if ( ! $vanished || $attempt >= 3 ) {
				throw new Exception(
					sprintf(
						'Zápis ZIP archivu selhal: %s. Záloha má před kompresí %s - pokud chyba zmiňuje místo nebo kvótu (quota, No space), hosting nemá na zálohy dost místa (uchovávají se %d).',
						$error,
						size_format( $list['bytes'] ),
						self::KEEP
					)
				);
			}

			foreach ( $vanished as $relative ) {
				unset( $files[ $relative ] );
				$skipped[] = $relative;
			}
		}
	}

	/* ------------------------------------------------------------------ ověření integrity */

	/**
	 * Ověří integritu zálohy: každý soubor v archivu se rozbalí a jeho kontrolní součet
	 * (CRC32) se porovná s tím, který byl uložen při vytvoření. Dál zkontroluje, že archiv
	 * obsahuje databázi a že v něm nechybí žádný soubor oproti metadatům.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function verify( $zip_path ) {
		self::prepare_long_run();

		$result = self::check_zip( $zip_path );

		self::write_info(
			$zip_path,
			array(
				'verified_at' => time(),
				'verified_ok' => $result['ok'],
				'verify_msg'  => $result['message'],
			)
		);

		return $result;
	}

	private static function check_zip( $zip_path ) {
		$fail = function ( $message ) {
			return array(
				'ok'      => false,
				'message' => $message,
			);
		};

		if ( ! class_exists( 'ZipArchive' ) ) {
			return $fail( 'Na serveru chybí PHP rozšíření ZipArchive.' );
		}

		$zip  = new ZipArchive();
		$open = $zip->open( $zip_path, ZipArchive::CHECKCONS );
		if ( true !== $open ) {
			return $fail( 'Soubor je poškozený nebo to není ZIP (kód ' . $open . ').' );
		}

		$meta = self::read_meta( $zip );
		if ( false === $zip->locateName( 'database.sql' ) || ! $meta ) {
			$zip->close();
			return $fail( 'Nejde o zálohu z pluginu Velrion Backup (chybí databáze nebo metadata).' );
		}

		$files = 0;
		$bad   = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! $stat || '/' === substr( $stat['name'], -1 ) ) {
				continue;
			}

			$stream = $zip->getStream( $stat['name'] );
			if ( ! $stream ) {
				$bad[] = $stat['name'];
				continue;
			}

			$ctx  = hash_init( 'crc32b' );
			$size = @hash_update_stream( $ctx, $stream ); // CRC chybu hlásí warningem - vyhodnotíme ji níže
			fclose( $stream );

			if ( hash_final( $ctx ) !== sprintf( '%08x', $stat['crc'] & 0xFFFFFFFF ) || $size !== $stat['size'] ) {
				$bad[] = $stat['name'];
			}

			if ( 0 === strpos( $stat['name'], 'files/' ) || 0 === strpos( $stat['name'], 'wp-content/' ) ) {
				++$files;
			}
		}

		$zip->close();

		if ( $bad ) {
			return $fail( count( $bad ) . ' poškozených souborů v archivu (např. ' . implode( ', ', array_slice( $bad, 0, 3 ) ) . ').' );
		}

		if ( isset( $meta['files'] ) && (int) $meta['files'] !== $files ) {
			return $fail( 'V archivu chybí soubory: očekáváno ' . (int) $meta['files'] . ', nalezeno ' . $files . '.' );
		}

		return array(
			'ok'      => true,
			'message' => 'Databáze a ' . number_format( $files, 0, ',', ' ' ) . ' souborů v pořádku.',
		);
	}

	/** Metadata z archivu - nový formát (velrion-backup.json) i starší verze pluginu (site-meta.json). */
	private static function read_meta( ZipArchive $zip ) {
		foreach ( array( 'velrion-backup.json', 'site-meta.json' ) as $name ) {
			$raw = $zip->getFromName( $name );
			if ( false !== $raw ) {
				$meta           = json_decode( $raw, true );
				$meta           = is_array( $meta ) ? $meta : array();
				$meta['legacy'] = 'site-meta.json' === $name;
				return $meta;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ obnova */

	/**
	 * Obnoví web ze zálohy - z tohoto webu i z jiného (pak přepíše doménu a prefix tabulek).
	 * Před obnovou zálohu ověří; poškozenou zálohu neobnoví vůbec.
	 * Nepřepisuje wp-config.php (přístupy k databázi tohoto serveru) ani tento plugin.
	 *
	 * @throws Exception
	 */
	public static function restore( $zip_path, $label ) {
		global $wpdb;

		if ( ! self::lock() ) {
			throw new Exception( 'Právě probíhá jiná záloha nebo obnova.' );
		}

		self::prepare_long_run();

		$sql = '';

		try {
			$check = self::check_zip( $zip_path );
			if ( ! $check['ok'] ) {
				throw new Exception( 'Záloha neprošla kontrolou integrity, obnova se nespustila: ' . $check['message'] );
			}

			$dir = self::prepare_dir();
			$zip = new ZipArchive();
			if ( true !== $zip->open( $zip_path ) ) {
				throw new Exception( 'ZIP se nepodařilo otevřít.' );
			}
			$meta = self::read_meta( $zip );

			// Hodnoty, které musí zůstat podle tohoto webu: doména a vlastní nastavení pluginu
			// (adresář záloh, klíč cronu, plán). Import databáze by je přepsal.
			$target_home = untrailingslashit( home_url() );
			$target_site = untrailingslashit( site_url() );
			$own_options = $wpdb->get_results(
				$wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'velrion_backup_' ) . '%' ),
				ARRAY_A
			);

			// Přihlášení uživatele, který obnovu spustil - jinak by ho obnova odhlásila
			// (relace se ukládají v databázi a obnovily by se ty z doby zálohy).
			$user_id  = get_current_user_id();
			$user     = $user_id ? wp_get_current_user()->user_login : '';
			$sessions = $user_id ? get_user_meta( $user_id, 'session_tokens', true ) : '';

			// Databáze.
			$sql = $dir . '/database.sql';
			if ( ! $zip->extractTo( $dir, 'database.sql' ) ) {
				throw new Exception( 'Databázi ze zálohy se nepodařilo rozbalit (místo na disku?).' );
			}

			$old_prefix = ! empty( $meta['table_prefix'] ) ? $meta['table_prefix'] : $wpdb->prefix;
			Velrion_Backup_DB::restore( $sql, $old_prefix, $wpdb->prefix, ! empty( $meta['legacy'] ) );
			@unlink( $sql );
			$sql = '';

			wp_cache_flush();

			foreach ( $own_options as $row ) {
				$wpdb->replace( $wpdb->options, $row );
			}
			wp_cache_flush();

			$restored_user = $user_id ? get_user_by( 'id', $user_id ) : false;
			if ( $sessions && $restored_user && $restored_user->user_login === $user ) {
				update_user_meta( $user_id, 'session_tokens', $sessions );
			}

			update_option( 'siteurl', $target_site );
			update_option( 'home', $target_home );

			$old_home = ! empty( $meta['home_url'] ) ? untrailingslashit( $meta['home_url'] ) : '';
			$old_site = ! empty( $meta['site_url'] ) ? untrailingslashit( $meta['site_url'] ) : '';
			if ( $old_home && $old_home !== $target_home ) {
				Velrion_Backup_DB::search_replace( $old_home, $target_home );
			}
			if ( $old_site && $old_site !== $old_home && $old_site !== $target_site ) {
				Velrion_Backup_DB::search_replace( $old_site, $target_site );
			}

			// Soubory.
			$written = self::restore_files( $zip, ! empty( $meta['legacy'] ) );
			$zip->close();

			self::schedule_wp_cron();

			self::update_state(
				array(
					'restore_time'   => time(),
					'restore_file'   => $label,
					'restore_status' => 'success',
					'restore_msg'    => 'Obnovena databáze a ' . number_format( $written, 0, ',', ' ' ) . ' souborů.',
				)
			);
		} catch ( Exception $e ) {
			if ( $sql ) {
				@unlink( $sql );
			}

			self::update_state(
				array(
					'restore_time'   => time(),
					'restore_file'   => $label,
					'restore_status' => 'error',
					'restore_msg'    => $e->getMessage(),
				)
			);

			self::unlock();
			throw $e;
		}

		self::unlock();
	}

	/**
	 * Zapíše soubory z archivu přímo na místo (bez dočasného rozbalení - nepotřebuje
	 * dvojnásobek místa na disku).
	 *
	 * @throws Exception
	 */
	private static function restore_files( ZipArchive $zip, $legacy ) {
		// Starší verze pluginu ukládaly jen wp-content/ (bez files/ prefixu).
		$prefix = $legacy ? 'wp-content/' : 'files/';
		$target = $legacy ? untrailingslashit( WP_CONTENT_DIR ) . '/' : trailingslashit( ABSPATH );

		$skip = array(
			'wp-config.php',
			'wp-content/plugins/' . basename( VELRION_BACKUP_PATH ) . '/',
		);

		$written = 0;
		$failed  = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( 0 !== strpos( $name, $prefix ) || '/' === substr( $name, -1 ) ) {
				continue;
			}

			$relative = substr( $name, strlen( $prefix ) );
			$check    = $legacy ? 'wp-content/' . $relative : $relative;

			if ( '' === $relative || false !== strpos( $relative, '..' ) || $check === $skip[0] || 0 === strpos( $check, $skip[1] ) ) {
				continue;
			}

			$dest = $target . $relative;
			wp_mkdir_p( dirname( $dest ) );

			$in  = $zip->getStream( $name );
			$out = $in ? @fopen( $dest, 'wb' ) : false;

			if ( ! $out ) {
				$failed[] = $relative;
				if ( $in ) {
					fclose( $in );
				}
				continue;
			}

			stream_copy_to_stream( $in, $out );
			fclose( $in );
			fclose( $out );
			++$written;
		}

		if ( $failed ) {
			throw new Exception( 'Databáze je obnovená, ale ' . count( $failed ) . ' souborů nešlo zapsat (např. ' . implode( ', ', array_slice( $failed, 0, 3 ) ) . '). Zkontrolujte práva k souborům.' );
		}

		return $written;
	}

	/* ------------------------------------------------------------------ plánování */

	/** Poslední plánovaný termín zálohy, který už nastal (timestamp). */
	public static function last_slot( $settings, $now = null ) {
		$tz   = wp_timezone();
		$now  = ( new DateTimeImmutable( '@' . ( $now ? $now : time() ) ) )->setTimezone( $tz );
		$slot = $now->setTime( (int) $settings['hour'], 0 );

		if ( 'weekly' === $settings['frequency'] ) {
			$back = ( (int) $now->format( 'w' ) - (int) $settings['weekday'] + 7 ) % 7;
			$slot = $slot->modify( "-{$back} days" );
			if ( $slot > $now ) {
				$slot = $slot->modify( '-7 days' );
			}
		} elseif ( $slot > $now ) {
			$slot = $slot->modify( '-1 day' );
		}

		return $slot->getTimestamp();
	}

	public static function next_slot( $settings ) {
		$last = ( new DateTimeImmutable( '@' . self::last_slot( $settings ) ) )->setTimezone( wp_timezone() );
		return $last->modify( 'weekly' === $settings['frequency'] ? '+7 days' : '+1 day' )->getTimestamp();
	}

	/** Je automatická záloha na řadě? (termín nastal a ještě nebyl vyřízený) */
	public static function is_due() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		$state = self::state();
		return self::last_slot( $settings ) > max( (int) $state['auto_slot'], (int) $state['enabled_at'] );
	}

	/**
	 * Volá ho cron jednou za hodinu (systémový přes URL nebo cron.php, případně WP-Cron).
	 *
	 * @param string $source system | wp-cron
	 * @return string Text pro výstup cronu.
	 */
	public static function tick( $source ) {
		$ping            = (array) get_option( self::OPT_PING, array() );
		$times           = self::pings( $source );
		$times[]         = time();
		$ping[ $source ] = array_slice( $times, -5 );
		update_option( self::OPT_PING, $ping, false );

		if ( ! self::is_due() ) {
			$settings = self::settings();
			return empty( $settings['enabled'] )
				? 'OK - automatické zálohování je vypnuté.'
				: 'OK - záloha není na řadě, další ' . wp_date( 'j. n. Y H:i', self::next_slot( $settings ) ) . '.';
		}

		if ( self::is_running() ) {
			return 'OK - záloha už právě běží.';
		}

		$slot  = self::last_slot( self::settings() );
		$state = self::state();

		$attempts = (int) $state['attempt_slot'] === $slot ? (int) $state['attempts'] + 1 : 1;
		if ( $attempts > self::MAX_ATTEMPTS ) {
			// Ani opakované pokusy nevyšly - tento termín vzdáme, chyba je vidět v adminu
			// a odešel e-mail. Další pokus až v příštím termínu.
			self::update_state( array( 'auto_slot' => $slot ) );
			return 'Chyba - záloha selhala ' . self::MAX_ATTEMPTS . '× po sobě, další pokus v příštím termínu.';
		}

		// Uložit pokus PŘED během: když PHP proces během zálohy zabije hosting (časový
		// limit), další spuštění cronu to pozná a nezkouší to donekonečna.
		self::update_state(
			array(
				'attempt_slot' => $slot,
				'attempts'     => $attempts,
			)
		);

		$result = self::create( 'auto' );
		if ( $result['ok'] ) {
			self::update_state(
				array(
					'auto_slot' => $slot,
					'attempts'  => 0,
				)
			);
		}

		return ( $result['ok'] ? 'OK - ' : 'Chyba - ' ) . $result['message'];
	}

	/** Pojistka: hodinová úloha ve WP-Cronu (funguje, když WP-Cron na webu běží). */
	public static function schedule_wp_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
		// Úloha ze starších verzí pluginu.
		if ( wp_next_scheduled( 'velrion_backup_run_event' ) ) {
			wp_clear_scheduled_hook( 'velrion_backup_run_event' );
		}
	}

	/** Časy posledních (max. 5) zavolání z daného zdroje, od nejstaršího. */
	private static function pings( $source ) {
		$ping = (array) get_option( self::OPT_PING, array() );
		// Verze 2.0.0-2.0.1 ukládaly jen jedno číslo.
		return isset( $ping[ $source ] ) ? array_map( 'intval', (array) $ping[ $source ] ) : array();
	}

	/** Zaznamená volání adresy cronu se špatným nebo chybějícím klíčem (např. useknutá URL). */
	public static function record_rejected() {
		$ping             = (array) get_option( self::OPT_PING, array() );
		$ping['rejected'] = time();
		update_option( self::OPT_PING, $ping, false );
	}

	/**
	 * Běží zdroj pravidelně každou hodinu? Nestačí jedno zavolání (to může být i ruční
	 * otevření adresy v prohlížeči) - poslední dvě musí být od sebe nejvýš hodinu a čtvrt
	 * a to poslední nesmí být starší.
	 */
	private static function is_hourly( $times ) {
		$limit = 75 * MINUTE_IN_SECONDS;
		$count = count( $times );

		return $count >= 2 && $times[ $count - 1 ] > time() - $limit && $times[ $count - 1 ] - $times[ $count - 2 ] <= $limit;
	}

	/**
	 * Stav cronu pro status bar.
	 *
	 * @return array{level:string,label:string,detail:string}
	 */
	public static function cron_health() {
		$system   = self::pings( 'system' );
		$wp       = self::pings( 'wp-cron' );
		$last_sys = $system ? max( $system ) : 0;
		$last_wp  = $wp ? max( $wp ) : 0;
		$ping     = (array) get_option( self::OPT_PING, array() );
		$rejected = isset( $ping['rejected'] ) ? (int) $ping['rejected'] : 0;

		if ( self::is_hourly( $system ) ) {
			$health = array( 'ok', 'Běží (systémový cron)', 'Naposledy ' . self::when( $last_sys ) );
		} elseif ( $last_sys > time() - 75 * MINUTE_IN_SECONDS ) {
			$health = array( 'warn', 'Čeká na potvrzení', 'Volání ' . self::when( $last_sys ) . ' přijato - potvrdí ho další hodinové spuštění. Ruční otevření adresy se počítá také.' );
		} elseif ( self::is_hourly( $wp ) ) {
			$health = array( 'warn', 'Běží jen přes WP-Cron', 'Závisí na návštěvnosti - nastavte systémový cron' );
		} elseif ( $last_sys ) {
			$health = array( 'error', 'Neběží', 'Poslední volání ' . self::when( $last_sys ) . ', cron má volat každou hodinu' );
		} else {
			$health = array( 'error', 'Neběží', 'Systémový cron plugin zatím nikdy nezavolal' );
		}

		if ( 'ok' !== $health[0] && $last_wp && ! self::is_hourly( $wp ) ) {
			$health[2] .= '. WP-Cron naposledy ' . self::when( $last_wp ) . ' (nepravidelně)';
		}

		if ( $rejected > time() - DAY_IN_SECONDS && $rejected > $last_sys ) {
			$health[0]  = 'ok' === $health[0] ? 'warn' : 'error';
			$health[2] .='. POZOR: ' . self::when( $rejected ) . ' přišlo volání se špatným nebo chybějícím klíčem - zkontrolujte adresu v cronu (u příkazu wget musí být v uvozovkách)';
		}

		return array(
			'level'  => $health[0],
			'label'  => $health[1],
			'detail' => $health[2],
		);
	}

	/** Přesný čas + kolik je to zpátky, např. "2. 10. 20:00 (před 2 hodiny)". */
	public static function when( $time ) {
		return wp_date( 'j. n. H:i', $time ) . ' (' . self::ago( $time ) . ')';
	}

	public static function ago( $time ) {
		return sprintf( 'před %s', human_time_diff( $time, time() ) );
	}
}
