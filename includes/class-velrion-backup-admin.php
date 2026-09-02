<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administrační stránka nastavení, ručního spuštění a obnovy v menu Nastavení.
 */
class Velrion_Backup_Admin {

	const SLUG = 'velrion-backup';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_velrion_backup_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_velrion_backup_run_now', array( __CLASS__, 'handle_run_now' ) );
		add_action( 'admin_post_velrion_backup_restore_local', array( __CLASS__, 'handle_restore_local' ) );
		add_action( 'admin_post_velrion_backup_restore_upload', array( __CLASS__, 'handle_restore_upload' ) );
	}

	public static function add_menu() {
		add_options_page(
			'Velrion Backup',
			'Velrion Backup',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nedostatečné oprávnění.' );
		}
		check_admin_referer( 'velrion_backup_save_settings' );

		$old_settings = Velrion_Backup_Core::get_settings();

		$weekday = isset( $_POST['weekday'] ) ? (int) $_POST['weekday'] : 0;
		$hour    = isset( $_POST['hour'] ) ? (int) $_POST['hour'] : 3;
		$dir     = isset( $_POST['backup_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_dir'] ) ) : '';

		$settings = array(
			'enabled'          => ! empty( $_POST['enabled'] ),
			'weekday'          => max( 0, min( 6, $weekday ) ),
			'hour'             => max( 0, min( 23, $hour ) ),
			'backup_dir'       => $dir !== '' ? $dir : $old_settings['backup_dir'],
			'email_on_failure' => ! empty( $_POST['email_on_failure'] ),
		);

		update_option( VELRION_BACKUP_OPTION, $settings );

		if ( $settings['enabled'] ) {
			Velrion_Backup_Cron::schedule( $settings['weekday'], $settings['hour'] );
		} else {
			Velrion_Backup_Cron::unschedule();
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function handle_run_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nedostatečné oprávnění.' );
		}
		check_admin_referer( 'velrion_backup_run_now' );

		$result = Velrion_Backup_Core::run();

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'ran' => $result ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function handle_restore_local() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nedostatečné oprávnění.' );
		}
		check_admin_referer( 'velrion_backup_restore_local' );

		if ( empty( $_POST['confirm'] ) ) {
			self::redirect_restore_error( 'Musíte potvrdit, že rozumíte důsledkům obnovy.' );
		}

		$filename = isset( $_POST['backup_file'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_file'] ) ) : '';

		try {
			$path = Velrion_Backup_Core::resolve_local_backup_path( $filename );
			Velrion_Backup_Core::restore( $path, basename( $path ) );
		} catch ( Exception $e ) {
			self::redirect_restore_error( $e->getMessage() );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'restored' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function handle_restore_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nedostatečné oprávnění.' );
		}
		check_admin_referer( 'velrion_backup_restore_upload' );

		if ( empty( $_POST['confirm'] ) ) {
			self::redirect_restore_error( 'Musíte potvrdit, že rozumíte důsledkům obnovy.' );
		}

		if ( empty( $_FILES['restore_zip'] ) || ! isset( $_FILES['restore_zip']['error'] ) || $_FILES['restore_zip']['error'] !== UPLOAD_ERR_OK ) {
			self::redirect_restore_error( 'Nahrání souboru selhalo. Zkontrolujte velikost souboru a limity serveru (upload_max_filesize).' );
		}

		$original_name = sanitize_file_name( $_FILES['restore_zip']['name'] );
		if ( strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) !== 'zip' ) {
			self::redirect_restore_error( 'Nahraný soubor musí být ZIP vytvořený pluginem Velrion Backup.' );
		}

		$settings   = Velrion_Backup_Core::get_settings();
		$backup_dir = $settings['backup_dir'];
		if ( ! file_exists( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}

		$tmp_path = trailingslashit( $backup_dir ) . 'uploaded-restore-' . substr( wp_generate_password( 12, false, false ), 0, 8 ) . '.zip';

		if ( ! is_uploaded_file( $_FILES['restore_zip']['tmp_name'] ) || ! move_uploaded_file( $_FILES['restore_zip']['tmp_name'], $tmp_path ) ) {
			self::redirect_restore_error( 'Nahraný soubor se nepodařilo uložit na server.' );
		}

		$error = '';
		try {
			Velrion_Backup_Core::restore( $tmp_path, $original_name . ' (nahráno ručně)' );
		} catch ( Exception $e ) {
			$error = $e->getMessage();
		}

		@unlink( $tmp_path );

		if ( $error !== '' ) {
			self::redirect_restore_error( $error );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'restored' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	private static function redirect_restore_error( $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::SLUG,
					'restore_error' => rawurlencode( $message ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Velrion_Backup_Core::get_settings();
		$state    = Velrion_Backup_Core::get_state();
		$backups  = Velrion_Backup_Core::list_backups( $settings );

		$weekdays = array(
			0 => 'Neděle',
			1 => 'Pondělí',
			2 => 'Úterý',
			3 => 'Středa',
			4 => 'Čtvrtek',
			5 => 'Pátek',
			6 => 'Sobota',
		);
		?>
		<div class="wrap">
			<h1>Velrion Backup</h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success"><p>Nastavení uloženo.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['ran'] ) ) : ?>
				<?php $ran = sanitize_key( wp_unslash( $_GET['ran'] ) ); ?>
				<div class="notice notice-<?php echo $ran === 'success' ? 'success' : ( $ran === 'locked' ? 'warning' : 'error' ); ?>">
					<p>
						<?php
						if ( $ran === 'success' ) {
							echo 'Záloha proběhla úspěšně.';
						} elseif ( $ran === 'locked' ) {
							echo 'Právě probíhá jiná záloha nebo obnova, nová záloha se nespustila. Zkuste to prosím za chvíli znovu.';
						} else {
							echo 'Záloha selhala: ' . esc_html( $state['message'] );
						}
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['restore_error'] ) ) : ?>
				<div class="notice notice-error"><p>Obnova selhala: <?php echo esc_html( wp_unslash( $_GET['restore_error'] ) ); ?></p></div>
			<?php elseif ( isset( $_GET['restored'] ) ) : ?>
				<div class="notice notice-<?php echo $state['last_restore_status'] === 'success' ? 'success' : 'error'; ?>">
					<p>
						<?php
						echo $state['last_restore_status'] === 'success'
							? 'Obnova proběhla úspěšně.'
							: 'Obnova selhala: ' . esc_html( $state['last_restore_message'] );
						?>
					</p>
				</div>
			<?php endif; ?>

			<h2>Stav poslední zálohy</h2>
			<table class="widefat" style="max-width:600px">
				<tbody>
					<tr>
						<th>Poslední spuštění</th>
						<td><?php echo $state['last_run'] ? esc_html( wp_date( 'j. n. Y H:i', $state['last_run'] ) ) : 'zatím neproběhlo'; ?></td>
					</tr>
					<tr>
						<th>Výsledek</th>
						<td>
							<?php
							if ( $state['status'] === 'success' ) {
								echo '<span style="color:green">OK</span>';
							} elseif ( $state['status'] === 'error' ) {
								echo '<span style="color:#b32d2e">Chyba: ' . esc_html( $state['message'] ) . '</span>';
							} else {
								echo '—';
							}
							?>
						</td>
					</tr>
					<tr>
						<th>Velikost zálohy</th>
						<td><?php echo $state['size'] ? esc_html( size_format( $state['size'] ) ) : '—'; ?></td>
					</tr>
					<tr>
						<th>Doba trvání</th>
						<td><?php echo $state['duration'] ? esc_html( $state['duration'] ) . ' s' : '—'; ?></td>
					</tr>
					<?php if ( ! empty( $state['last_restore'] ) ) : ?>
					<tr>
						<th>Poslední obnova</th>
						<td>
							<?php echo esc_html( wp_date( 'j. n. Y H:i', $state['last_restore'] ) ); ?>
							ze souboru <code><?php echo esc_html( $state['last_restore_source'] ); ?></code> -
							<?php echo $state['last_restore_status'] === 'success' ? '<span style="color:green">OK</span>' : '<span style="color:#b32d2e">chyba</span>'; ?>
						</td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'velrion_backup_run_now' ); ?>
					<input type="hidden" name="action" value="velrion_backup_run_now">
					<?php submit_button( 'Zálohovat nyní', 'secondary', 'submit', false ); ?>
				</form>
			</p>

			<h2>Uložené zálohy a obnova</h2>
			<p class="description">Soubory jsou pojmenované podle data a času pořízení. Uchovávají se 2 nejnovější, starší se automaticky mažou.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Opravdu obnovit vybranou zálohu? Přepíše to aktuální databázi a soubory pluginů/šablon tohoto webu.');">
				<?php wp_nonce_field( 'velrion_backup_restore_local' ); ?>
				<input type="hidden" name="action" value="velrion_backup_restore_local">
				<table class="widefat" style="max-width:650px">
					<thead>
						<tr>
							<th style="width:2em"></th>
							<th>Soubor</th>
							<th>Aktualizováno</th>
							<th>Velikost</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $backups as $index => $backup ) : ?>
							<tr>
								<td>
									<?php if ( $backup['exists'] ) : ?>
										<input type="radio" name="backup_file" value="<?php echo esc_attr( basename( $backup['path'] ) ); ?>" <?php checked( $index === 0 ); ?>>
									<?php endif; ?>
								</td>
								<td><code><?php echo $backup['exists'] ? esc_html( basename( $backup['path'] ) ) : '—'; ?></code></td>
								<td><?php echo $backup['exists'] ? esc_html( wp_date( 'j. n. Y H:i', $backup['mtime'] ) ) : 'prázdný slot'; ?></td>
								<td><?php echo $backup['exists'] ? esc_html( size_format( $backup['size'] ) ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><label><input type="checkbox" name="confirm" value="1" required> Rozumím, že tato akce nevratně přepíše aktuální databázi a soubory (pluginy, šablony) obsahem vybrané zálohy.</label></p>
				<?php submit_button( 'Obnovit vybranou zálohu', 'delete', 'submit', false ); ?>
			</form>
			<p class="description">Adresář: <code><?php echo esc_html( $settings['backup_dir'] ); ?></code></p>

			<h2>Obnova z nahraného souboru (jiný web)</h2>
			<p class="description">
				Pro přesun na jiný server/doménu: na zdrojovém webu s tímto pluginem si stáhněte ZIP z adresáře uvedeného výše,
				zde ho nahrajte. Pokud se doména nebo prefix tabulek liší, plugin je automaticky přepíše podle tohoto webu.
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" onsubmit="return confirm('Opravdu obnovit z nahraného souboru? Přepíše to aktuální databázi a soubory pluginů/šablon tohoto webu.');">
				<?php wp_nonce_field( 'velrion_backup_restore_upload' ); ?>
				<input type="hidden" name="action" value="velrion_backup_restore_upload">
				<p><input type="file" name="restore_zip" accept=".zip" required></p>
				<p><label><input type="checkbox" name="confirm" value="1" required> Rozumím, že tato akce nevratně přepíše aktuální databázi a soubory (pluginy, šablony) obsahem nahrané zálohy.</label></p>
				<?php submit_button( 'Nahrát a obnovit', 'delete', 'submit', false ); ?>
			</form>

			<h2>Nastavení</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'velrion_backup_save_settings' ); ?>
				<input type="hidden" name="action" value="velrion_backup_save_settings">

				<table class="form-table">
					<tr>
						<th scope="row">Automatické zálohování</th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
								Zapnuto (jednou týdně)
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Den v týdnu</th>
						<td>
							<select name="weekday">
								<?php foreach ( $weekdays as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (int) $settings['weekday'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Hodina</th>
						<td>
							<select name="hour">
								<?php for ( $h = 0; $h < 24; $h++ ) : ?>
									<option value="<?php echo esc_attr( $h ); ?>" <?php selected( (int) $settings['hour'], $h ); ?>>
										<?php echo esc_html( sprintf( '%02d:00', $h ) ); ?>
									</option>
								<?php endfor; ?>
							</select>
							<p class="description">Výchozí je 3:00 (mezi 3. a 4. hodinou ranní). Čas dle časové zóny nastavené ve Nastavení → Obecné.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Cesta pro ukládání zálohy</th>
						<td>
							<input type="text" name="backup_dir" value="<?php echo esc_attr( $settings['backup_dir'] ); ?>" class="regular-text">
							<p class="description">Výchozí umístění je mimo web-root, aby nebylo přístupné přes URL. Adresář musí existovat nebo jej PHP musí umět vytvořit. Nezávisí na doméně ani cestě webu - při instalaci na nový web se dopočítá automaticky.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">E-mail při chybě</th>
						<td>
							<label>
								<input type="checkbox" name="email_on_failure" value="1" <?php checked( $settings['email_on_failure'] ); ?>>
								Poslat e-mail administrátorovi, když záloha selže
							</label>
						</td>
					</tr>
				</table>

				<p class="description">Záloha obsahuje databázi a celý wp-content kromě složek uploads, cache a upgrade. Udržují se 2 zálohy, každé spuštění přepíše tu starší z nich.</p>

				<?php submit_button( 'Uložit nastavení' ); ?>
			</form>

			<hr>
			<p style="color:#666;font-size:12px">
				Velrion Backup v<?php echo esc_html( VELRION_BACKUP_VERSION ); ?> &mdash; vyvinula agentura
				<a href="https://velrionsolutions.com" target="_blank" rel="noopener noreferrer">Velrion Solutions</a>.
			</p>
		</div>
		<?php
	}
}
