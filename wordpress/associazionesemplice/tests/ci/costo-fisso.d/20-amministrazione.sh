# Richieste della bacheca, di admin-ajax.php e di admin-post.php (letto da costo-fisso.sh).
# I gestori delle azioni si caricano solo quando admin-post.php o admin-ajax.php ne chiede una del plugin: mai per il battito né per le pagine.
HANDLERS='Admin\Actions Admin\RegistersActions Admin\TechActions Admin\BlockBActions Admin\BlockFActions Admin\EmployeesPage Admin\Exports Admin\PersonSearch Admin\IncomePage Frontend\Actions'
# ... e con il Pro
HANDLERS+=' Admin\ProActions Admin\BlockCActions Admin\BlockDProActions Admin\BlockEActions Admin\WatchActions Admin\ResourcesActions Admin\CreditsActions Admin\BlockDActions Frontend\ProActions'
misura 'Battito della bacheca (amministratore)' POST '/wp-admin/admin-ajax.php' 'action=heartbeat&interval=60&screen_id=dashboard' 1 '200' "$HANDLERS Admin\Dismiss Admin\YearsPage Admin\RuntsPage Admin\WpDashboard Wizard"
misura 'Battito (visitatore)' POST '/wp-admin/admin-ajax.php' 'action=heartbeat&interval=60' 0 '200 400' "$HANDLERS Admin\Dismiss Wizard"
misura 'Pagina della bacheca: Rubrica soci (amministratore)' GET '/wp-admin/admin.php?page=asem-people' '' 1 '200 302' "$HANDLERS Admin\YearsPage Admin\RuntsPage Admin\WpDashboard" 'Admin\Admin'
# Le azioni arrivano ancora al loro gestore: senza il nonce la risposta è il rifiuto del gestore stesso (403), non una pagina vuota
misura 'Azione della bacheca senza nonce (amministratore)' GET '/wp-admin/admin-post.php?action=asem_save_person' '' 1 '403' 'Frontend\Actions' 'Admin\Actions'
misura 'Modulo dell’area soci (visitatore)' GET '/wp-admin/admin-post.php?action=asem_front_profile' '' 0 '302 405' '' 'Frontend\Actions'
