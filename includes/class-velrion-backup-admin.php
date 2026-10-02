<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administrace: Nastavení → Velrion Backup.
 */
class Velrion_Backup_Admin {

	const SLUG = 'velrion-backup';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( 'Velrion_Backup', 'schedule_wp_cron' ) );

		foreach ( array( 'save', 'create', 'verify', 'restore', 'delete', 'download', 'upload' ) as $action ) {
			add_action( 'admin_post_velrion_backup_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	public static function menu() {
		add_options_page( 'Velrion Backup', 'Velrion Backup', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/* ------------------------------------------------------------------ akce */

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nedostatečné oprávnění.' );
		}
		check_admin_referer( 'velrion_backup_' . $action );
	}

	private static function done( $ok, $message, $args = array() ) {
		set_transient( 'velrion_backup_notice_' . get_current_user_id(), array( $ok, $message ), 300 );
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	private static function posted_path() {
		try {
			return Velrion_Backup::path_for( isset( $_REQUEST['file'] ) ? sanitize_file_name( wp_unslash( $_REQUEST['file'] ) ) : '' );
		} catch ( Exception $e ) {
			self::done( false, $e->getMessage() );
		}
	}

	public static function handle_save() {
		self::guard( 'save' );

		$old = Velrion_Backup::settings();
		$new = array(
			'enabled'          => ! empty( $_POST['enabled'] ),
			'frequency'        => isset( $_POST['frequency'] ) && 'daily' === $_POST['frequency'] ? 'daily' : 'weekly',
			'weekday'          => isset( $_POST['weekday'] ) ? max( 0, min( 6, (int) $_POST['weekday'] ) ) : 0,
			'hour'             => isset( $_POST['hour'] ) ? max( 0, min( 23, (int) $_POST['hour'] ) ) : 3,
			'email_on_failure' => ! empty( $_POST['email_on_failure'] ),
		);
		update_option( Velrion_Backup::OPT_SETTINGS, $new );

		// Termíny před uložením nastavení se nedohánějí - první záloha proběhne v nejbližším
		// budoucím termínu, ne hned po uložení.
		Velrion_Backup::update_state( array( 'enabled_at' => time() ) );
		Velrion_Backup::schedule_wp_cron();

		$just_enabled = $new['enabled'] && empty( $old['enabled'] );
		self::done(
			true,
			$just_enabled ? 'Automatické zálohování je zapnuté. Teď prosím nastavte cron podle návodu níže - bez něj se zálohy nespustí spolehlivě.' : 'Nastavení uloženo.',
			$just_enabled ? array( 'cron_help' => 1 ) : array()
		);
	}

	public static function handle_create() {
		self::guard( 'create' );
		$result = Velrion_Backup::create( 'manual' );
		self::done( $result['ok'], $result['message'] );
	}

	public static function handle_verify() {
		self::guard( 'verify' );
		$path   = self::posted_path();
		$result = Velrion_Backup::verify( $path );
		self::done( $result['ok'], basename( $path ) . ': ' . ( $result['ok'] ? 'integrita v pořádku - ' : 'POŠKOZENO - ' ) . $result['message'] );
	}

	public static function handle_restore() {
		self::guard( 'restore' );
		$path = self::posted_path();

		try {
			Velrion_Backup::restore( $path, basename( $path ) );
		} catch ( Exception $e ) {
			self::done( false, 'Obnova selhala: ' . $e->getMessage() );
		}

		self::done( true, 'Web byl obnoven ze zálohy ' . basename( $path ) . '. Pokud vás systém odhlásí, přihlaste se údaji platnými v době zálohy.' );
	}

	public static function handle_delete() {
		self::guard( 'delete' );
		$path = self::posted_path();
		Velrion_Backup::delete( $path );
		self::done( true, 'Záloha ' . basename( $path ) . ' smazána.' );
	}

	public static function handle_download() {
		self::guard( 'download' );
		$path = self::posted_path();

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		@set_time_limit( 0 );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		$handle = fopen( $path, 'rb' );
		while ( ! feof( $handle ) ) {
			echo fread( $handle, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput
			flush();
		}
		fclose( $handle );
		exit;
	}

	public static function handle_upload() {
		self::guard( 'upload' );

		$file = isset( $_FILES['zip'] ) ? $_FILES['zip'] : null;
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::done( false, 'Nahrání selhalo. Server přijme soubor nejvýš ' . size_format( wp_max_upload_size() ) . ' - větší ZIP nahrajte přes FTP do adresáře záloh, objeví se v seznamu.' );
		}
		if ( 'zip' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) {
			self::done( false, 'Nahraný soubor musí být ZIP.' );
		}

		// Uložit do adresáře záloh pod původním jménem: objeví se v seznamu, jde ověřit,
		// stáhnout nebo obnovit. Automatické mazání starých záloh se ho netýká.
		$name = preg_replace( '/[^A-Za-z0-9._-]/', '-', sanitize_file_name( $file['name'] ) );
		$dest = Velrion_Backup::dir() . '/' . $name;
		if ( ! wp_mkdir_p( dirname( $dest ) ) || ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			self::done( false, 'Nahraný soubor se nepodařilo uložit do adresáře záloh.' );
		}

		$check = Velrion_Backup::verify( $dest );
		if ( ! $check['ok'] ) {
			Velrion_Backup::delete( $dest );
			self::done( false, 'Nahraný ZIP neprošel kontrolou a byl smazán: ' . $check['message'] );
		}

		self::done( true, 'ZIP ' . $name . ' nahrán a ověřen. Obnovu spustíte tlačítkem Obnovit v seznamu záloh.' );
	}

	/* ------------------------------------------------------------------ stránka */

	private static function button( $action, $label, $file = '', $class = 'button', $confirm = '' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vb-inline"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : ''; ?>>
			<?php wp_nonce_field( 'velrion_backup_' . $action ); ?>
			<input type="hidden" name="action" value="velrion_backup_<?php echo esc_attr( $action ); ?>">
			<?php if ( $file ) : ?>
				<input type="hidden" name="file" value="<?php echo esc_attr( $file ); ?>">
			<?php endif; ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function tile( $level, $title, $value, $detail ) {
		?>
		<div class="vb-tile vb-<?php echo esc_attr( $level ); ?>">
			<div class="vb-tile-title"><?php echo esc_html( $title ); ?></div>
			<div class="vb-tile-value"><?php echo esc_html( $value ); ?></div>
			<div class="vb-tile-detail"><?php echo esc_html( $detail ); ?></div>
		</div>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Velrion_Backup::settings();
		$state    = Velrion_Backup::state();
		$backups  = Velrion_Backup::backups();
		$dir      = Velrion_Backup::dir();
		$cron     = Velrion_Backup::cron_health();
		$own      = count( wp_list_filter( $backups, array( 'own' => true ) ) );
		$latest   = $backups ? $backups[0] : null;
		$days     = array( 'neděli', 'pondělí', 'úterý', 'středu', 'čtvrtek', 'pátek', 'sobotu' );

		$notice_key = 'velrion_backup_notice_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		delete_transient( $notice_key );

		// Status bar.
		if ( Velrion_Backup::is_running() ) {
			$t_last = array( 'warn', 'Probíhá…', 'Záloha nebo obnova právě běží' );
		} elseif ( 'success' === $state['status'] ) {
			$t_last = array( 'ok', wp_date( 'j. n. Y H:i', $state['last_run'] ), 'OK · ' . size_format( $state['size'] ) . ' · ' . $state['duration'] . ' s · ' . ( 'auto' === $state['trigger'] ? 'automaticky' : 'ručně' ) );
		} elseif ( 'error' === $state['status'] ) {
			$t_last = array( 'error', 'Selhala ' . wp_date( 'j. n. H:i', $state['last_run'] ), $state['message'] );
		} else {
			$t_last = array( 'none', 'Zatím žádná', 'Vytvořte první zálohu' );
		}

		$info = $latest ? $latest['info'] : array();
		if ( ! $latest ) {
			$t_int = array( 'none', '—', 'Žádná záloha k ověření' );
		} elseif ( ! isset( $info['verified_ok'] ) ) {
			$t_int = array( 'warn', 'Neověřeno', $latest['file'] );
		} else {
			$t_int = $info['verified_ok']
				? array( 'ok', 'V pořádku', 'Nejnovější záloha ověřena ' . Velrion_Backup::ago( $info['verified_at'] ) )
				: array( 'error', 'Poškozená záloha', $info['verify_msg'] );
		}

		if ( empty( $settings['enabled'] ) ) {
			$t_auto = array( 'none', 'Vypnuto', 'Zapněte v nastavení níže' );
		} elseif ( Velrion_Backup::is_due() && $state['attempts'] && (int) $state['attempt_slot'] === Velrion_Backup::last_slot( $settings ) ) {
			$t_auto = array( 'error', 'Selhává', 'Pokus ' . (int) $state['attempts'] . ' z ' . Velrion_Backup::MAX_ATTEMPTS . ', další při příštím spuštění cronu' );
		} elseif ( Velrion_Backup::is_due() ) {
			$t_auto = array( 'error', 'Po termínu', 'ok' === $cron['level'] ? 'Proběhne při příštím spuštění cronu' : 'Cron neběží pravidelně - záloha se nespustí, dokud ho nenastavíte' );
		} else {
			$t_auto = array( 'ok', wp_date( 'j. n. Y H:i', Velrion_Backup::next_slot( $settings ) ), 'daily' === $settings['frequency'] ? 'Denně' : 'Každý týden' );
		}

		// S vypnutým automatickým zálohováním cron nepotřebujeme - nebarvit na červeno.
		$t_cron = array( empty( $settings['enabled'] ) && 'ok' !== $cron['level'] ? 'none' : $cron['level'], $cron['label'], $cron['detail'] );

		$free    = @disk_free_space( $dir );
		$t_store = array(
			$own ? 'ok' : 'none',
			$own . ' / ' . Velrion_Backup::KEEP . ' záloh',
			size_format( array_sum( wp_list_pluck( $backups, 'size' ) ) ) . ' zabráno' . ( false !== $free ? ' · ' . size_format( $free ) . ' volno' : '' ),
		);

		$show_cron_help = ! empty( $settings['enabled'] ) && ( 'ok' !== $cron['level'] || isset( $_GET['cron_help'] ) );
		?>
		<div class="wrap vb">
			<style>
				.vb-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:16px 0 20px;max-width:1100px}
				.vb-tile{background:#fff;border:1px solid #dcdcde;border-left:4px solid #8c8f94;border-radius:4px;padding:12px 14px}
				.vb-tile-title{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#646970}
				.vb-tile-value{font-size:16px;font-weight:600;margin:4px 0 2px}
				.vb-tile-detail{font-size:12px;color:#50575e;overflow-wrap:anywhere}
				.vb-ok{border-left-color:#00a32a}.vb-ok .vb-tile-value{color:#007017}
				.vb-warn{border-left-color:#dba617}.vb-warn .vb-tile-value{color:#996800}
				.vb-error{border-left-color:#d63638}.vb-error .vb-tile-value{color:#b32d2e}
				.vb-card{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:4px 20px 16px;margin-bottom:20px;max-width:1100px;box-sizing:border-box}
				.vb-inline{display:inline-block;margin:0 4px 4px 0}
				.vb table.widefat td{vertical-align:middle}
				.vb code.vb-cmd{display:block;padding:8px 10px;margin:4px 0 10px;overflow-wrap:anywhere;user-select:all}
				.vb-cron-help{border-left:4px solid #dba617}
				.vb-badge{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;background:#f0f0f1}
				.vb-badge.ok{background:#edfaef;color:#007017}.vb-badge.error{background:#fcf0f1;color:#b32d2e}
			</style>

			<h1>Velrion Backup</h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo $notice[0] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<div class="vb-bar">
				<?php
				self::tile( $t_last[0], 'Poslední záloha', $t_last[1], $t_last[2] );
				self::tile( $t_int[0], 'Integrita', $t_int[1], $t_int[2] );
				self::tile( $t_auto[0], 'Příští automatická', $t_auto[1], $t_auto[2] );
				self::tile( $t_cron[0], 'Cron', $t_cron[1], $t_cron[2] );
				self::tile( $t_store[0], 'Úložiště', $t_store[1], $t_store[2] );
				?>
			</div>

			<?php
			if ( $show_cron_help ) {
				self::cron_help( true );
			}
			?>

			<div class="vb-card">
				<h2>Zálohy</h2>
				<p>
					<?php self::button( 'create', 'Zálohovat nyní', '', 'button button-primary' ); ?>
					<span class="description">Kompletní záloha: databáze a všechny soubory webu (WordPress, šablony, pluginy, média). Uchovávají se <?php echo (int) Velrion_Backup::KEEP; ?> nejnovější, nová přepíše nejstarší.</span>
				</p>
				<table class="widefat striped">
					<thead><tr><th>Soubor</th><th>Vytvořeno</th><th>Velikost</th><th>Integrita</th><th>Akce</th></tr></thead>
					<tbody>
					<?php if ( ! $backups ) : ?>
						<tr><td colspan="5">Zatím žádné zálohy.</td></tr>
					<?php endif; ?>
					<?php foreach ( $backups as $b ) : ?>
						<?php $i = $b['info']; ?>
						<tr>
							<td>
								<code><?php echo esc_html( $b['file'] ); ?></code><br>
								<span class="description"><?php echo esc_html( ! $b['own'] ? 'nahraný soubor' : ( isset( $i['trigger'] ) && 'auto' === $i['trigger'] ? 'automatická' : 'ruční' ) ); ?></span>
							</td>
							<td><?php echo esc_html( wp_date( 'j. n. Y H:i', $b['time'] ) ); ?></td>
							<td><?php echo esc_html( size_format( $b['size'] ) ); ?></td>
							<td>
								<?php if ( ! isset( $i['verified_ok'] ) ) : ?>
									<span class="vb-badge">neověřeno</span>
								<?php else : ?>
									<span class="vb-badge <?php echo $i['verified_ok'] ? 'ok' : 'error'; ?>" title="<?php echo esc_attr( $i['verify_msg'] ); ?>">
										<?php echo $i['verified_ok'] ? 'v pořádku' : 'poškozeno'; ?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<a class="button vb-inline" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=velrion_backup_download&file=' . rawurlencode( $b['file'] ) ), 'velrion_backup_download' ) ); ?>">Stáhnout</a>
								<?php
								self::button( 'verify', 'Ověřit', $b['file'] );
								self::button( 'restore', 'Obnovit', $b['file'], 'button', 'Obnovit web ze zálohy ' . $b['file'] . '? Aktuální databáze a soubory webu budou nevratně přepsány.' );
								self::button( 'delete', 'Smazat', $b['file'], 'button-link button-link-delete', 'Opravdu smazat zálohu ' . $b['file'] . '?' );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					Adresář: <code><?php echo esc_html( $dir ); ?></code>
					<?php if ( Velrion_Backup::is_dir_public( $dir ) ) : ?>
						<br><span style="color:#b32d2e">Adresář leží uvnitř webu (hosting nedovolil zapisovat mimo něj). Na Apache je chráněný .htaccess, na nginx ověřte, že zálohy nejdou stáhnout přes URL.</span>
					<?php endif; ?>
				</p>
				<?php if ( $state['restore_time'] ) : ?>
					<p class="description">
						Poslední obnova: <?php echo esc_html( wp_date( 'j. n. Y H:i', $state['restore_time'] ) . ' ze souboru ' . $state['restore_file'] . ' - ' ); ?>
						<strong style="color:<?php echo 'success' === $state['restore_status'] ? '#007017' : '#b32d2e'; ?>"><?php echo esc_html( $state['restore_msg'] ); ?></strong>
					</p>
				<?php endif; ?>
			</div>

			<div class="vb-card">
				<h2>Nahrát zálohu (obnova z jiného webu)</h2>
				<p>Nahrajte ZIP stažený z tohoto pluginu na jiném webu. Plugin ho ověří a přidá do seznamu záloh, kde ho tlačítkem <strong>Obnovit</strong> obnovíte. Doménu a prefix tabulek přepíše automaticky podle tohoto webu; <code>wp-config.php</code> se nepřepisuje.</p>
				<?php
				// Soubor nad limitem serveru PHP zahodí i s celým formulářem a uživatel by viděl
				// jen prázdnou stránku - proto kontrola velikosti už v prohlížeči.
				$max_upload = wp_max_upload_size();
				$too_big    = 'Soubor je větší než limit serveru (' . size_format( $max_upload ) . '). Nahrajte ho přes FTP do adresáře záloh - objeví se v seznamu.';
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" onsubmit="var f=this.zip.files[0];if(f&&f.size><?php echo (int) $max_upload; ?>){alert(<?php echo esc_attr( wp_json_encode( $too_big ) ); ?>);return false;}">
					<?php wp_nonce_field( 'velrion_backup_upload' ); ?>
					<input type="hidden" name="action" value="velrion_backup_upload">
					<input type="file" name="zip" accept=".zip" required>
					<button type="submit" class="button">Nahrát a ověřit</button>
					<p class="description">Maximální velikost nahrávaného souboru je <?php echo esc_html( size_format( $max_upload ) ); ?>. Větší ZIP nahrajte přes FTP do adresáře záloh (viz výše).</p>
				</form>
			</div>

			<div class="vb-card">
				<h2>Automatické zálohování</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'velrion_backup_save' ); ?>
					<input type="hidden" name="action" value="velrion_backup_save">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Zapnuto</th>
							<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>> Zálohovat automaticky</label></td>
						</tr>
						<tr>
							<th scope="row">Kdy</th>
							<td>
								<select name="frequency">
									<option value="weekly" <?php selected( $settings['frequency'], 'weekly' ); ?>>Každý týden</option>
									<option value="daily" <?php selected( $settings['frequency'], 'daily' ); ?>>Každý den</option>
								</select>
								<select name="weekday">
									<?php foreach ( $days as $n => $label ) : ?>
										<option value="<?php echo (int) $n; ?>" <?php selected( (int) $settings['weekday'], $n ); ?>>v <?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<select name="hour">
									<?php for ( $h = 0; $h < 24; $h++ ) : ?>
										<option value="<?php echo (int) $h; ?>" <?php selected( (int) $settings['hour'], $h ); ?>><?php echo esc_html( sprintf( 'v %d:00', $h ) ); ?></option>
									<?php endfor; ?>
								</select>
								<p class="description">Čas podle časového pásma webu (Nastavení → Obecné). Den v týdnu platí jen pro týdenní zálohu.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Upozornění</th>
							<td><label><input type="checkbox" name="email_on_failure" value="1" <?php checked( $settings['email_on_failure'] ); ?>> Poslat e-mail na <?php echo esc_html( get_option( 'admin_email' ) ); ?>, když automatická záloha selže</label></td>
						</tr>
					</table>
					<?php submit_button( 'Uložit nastavení' ); ?>
				</form>
			</div>

			<?php
			if ( ! empty( $settings['enabled'] ) && ! $show_cron_help ) {
				self::cron_help( false );
			}
			?>

			<p class="description">Velrion Backup v<?php echo esc_html( VELRION_BACKUP_VERSION ); ?> — vyvinula agentura <a href="https://velrionsolutions.com" target="_blank" rel="noopener noreferrer">Velrion Solutions</a>.</p>
		</div>
		<?php
	}

	/**
	 * Návod na nastavení systémového cronu. Nahoře a zvýrazněný, dokud cron neběží.
	 */
	private static function cron_help( $highlight ) {
		$url    = Velrion_Backup::cron_url();
		$script = wp_normalize_path( VELRION_BACKUP_PATH . 'cron.php' );
		?>
		<div class="vb-card <?php echo $highlight ? 'vb-cron-help' : ''; ?>">
			<h2><?php echo $highlight ? 'Nastavte cron u hostingu' : 'Nastavení cronu'; ?></h2>
			<p>
				Aby automatické zálohy běžely spolehlivě, nastavte v administraci hostingu <strong>cron úlohu spouštěnou každou hodinu</strong>.
				Plugin si při každém spuštění sám zkontroluje, jestli je záloha na řadě; když se nějaké spuštění vynechá, záloha se dožene při dalším.
				<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
					<br><strong>Na tomto webu je vestavěný WP-Cron vypnutý (DISABLE_WP_CRON v wp-config.php), systémový cron je proto nutný.</strong>
				<?php endif; ?>
			</p>

			<h3>Varianta A – adresa URL (umí každý hosting)</h3>
			<p>Do políčka pro URL zadejte:</p>
			<code class="vb-cmd"><?php echo esc_html( $url ); ?></code>
			<p>Pokud hosting chce místo URL příkaz:</p>
			<code class="vb-cmd">wget -q -O /dev/null -t 1 --timeout=3600 "<?php echo esc_html( $url ); ?>"</code>

			<h3>Varianta B – PHP skript (nejspolehlivější, bez časových limitů webserveru)</h3>
			<p>Pokud hosting umí z cronu spouštět PHP skripty:</p>
			<code class="vb-cmd">php <?php echo esc_html( $script ); ?></code>

			<p>
				<strong>Interval:</strong> každou hodinu (např. v 0. minutě).
				<strong>Ověření:</strong> do hodiny po nastavení musí dlaždice <em>Cron</em> nahoře ukázat „Běží (systémový cron)“.
				Adresu si můžete hned vyzkoušet: <a href="<?php echo esc_url( add_query_arg( 'test', 1, $url ) ); ?>" target="_blank" rel="noopener noreferrer">otevřít testovací adresu</a> (zálohu nespustí).
			</p>
			<p class="description">Adresa obsahuje tajný klíč - nesdílejte ji veřejně.</p>
		</div>
		<?php
	}
}
