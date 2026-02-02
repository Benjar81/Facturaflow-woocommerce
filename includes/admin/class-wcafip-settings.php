<?php
/**
 * Clase Settings - Configuración del plugin
 * 
 * @package WC_AFIP_Facturacion
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAFIP_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_wcafip_upload_cert', array($this, 'ajax_upload_cert'));
        add_action('wp_ajax_wcafip_test_connection', array($this, 'ajax_test_connection'));
    }
    
    /**
     * Registrar settings
     */
    public function register_settings() {
        // Sección: Datos Fiscales
        add_settings_section('wcafip_datos_fiscales', __('Datos Fiscales del Emisor', 'wc-afip-facturacion'), null, 'wcafip-settings');
        
        register_setting('wcafip-settings', 'wcafip_razon_social');
        register_setting('wcafip-settings', 'wcafip_cuit');
        register_setting('wcafip-settings', 'wcafip_condicion_iva');
        register_setting('wcafip-settings', 'wcafip_domicilio');
        register_setting('wcafip-settings', 'wcafip_ingresos_brutos');
        register_setting('wcafip-settings', 'wcafip_inicio_actividades');
        register_setting('wcafip-settings', 'wcafip_punto_venta');
        
        // Sección: AFIP
        add_settings_section('wcafip_afip', __('Configuración AFIP', 'wc-afip-facturacion'), null, 'wcafip-settings');
        
        register_setting('wcafip-settings', 'wcafip_ambiente');
        register_setting('wcafip-settings', 'wcafip_cert_file');
        register_setting('wcafip-settings', 'wcafip_key_file');
        
        // Sección: Facturación
        add_settings_section('wcafip_facturacion', __('Configuración de Facturación', 'wc-afip-facturacion'), null, 'wcafip-settings');
        
        register_setting('wcafip-settings', 'wcafip_permitir_factura_a');
        register_setting('wcafip-settings', 'wcafip_facturacion_automatica');
        register_setting('wcafip-settings', 'wcafip_estados_facturar');
        register_setting('wcafip-settings', 'wcafip_enviar_email');
    }
    
    /**
     * Render página de configuración
     */
    public function render_page() {
        if (isset($_POST['submit']) && check_admin_referer('wcafip_settings_nonce')) {
            $this->save_settings();
        }
        
        $upload_dir = wp_upload_dir();
        $certs_dir = $upload_dir['basedir'] . '/afip-certs';
        
        ?>
        <div class="wrap">
            <h1><?php _e('Configuración AFIP Facturación', 'wc-afip-facturacion'); ?></h1>
            
            <form method="post" action="" enctype="multipart/form-data">
                <?php wp_nonce_field('wcafip_settings_nonce'); ?>
                
                <!-- Datos Fiscales -->
                <h2><?php _e('Datos Fiscales del Emisor', 'wc-afip-facturacion'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="wcafip_razon_social"><?php _e('Razón Social', 'wc-afip-facturacion'); ?></label></th>
                        <td><input type="text" name="wcafip_razon_social" id="wcafip_razon_social" value="<?php echo esc_attr(get_option('wcafip_razon_social')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="wcafip_cuit"><?php _e('CUIT', 'wc-afip-facturacion'); ?></label></th>
                        <td><input type="text" name="wcafip_cuit" id="wcafip_cuit" value="<?php echo esc_attr(get_option('wcafip_cuit')); ?>" class="regular-text" placeholder="20-12345678-9"></td>
                    </tr>
                    <tr>
                        <th><label for="wcafip_condicion_iva"><?php _e('Condición ante IVA', 'wc-afip-facturacion'); ?></label></th>
                        <td>
                            <select name="wcafip_condicion_iva" id="wcafip_condicion_iva">
                                <option value="responsable_inscripto" <?php selected(get_option('wcafip_condicion_iva'), 'responsable_inscripto'); ?>><?php _e('IVA Responsable Inscripto', 'wc-afip-facturacion'); ?></option>
                                <option value="monotributo" <?php selected(get_option('wcafip_condicion_iva'), 'monotributo'); ?>><?php _e('Responsable Monotributo', 'wc-afip-facturacion'); ?></option>
                                <option value="exento" <?php selected(get_option('wcafip_condicion_iva'), 'exento'); ?>><?php _e('IVA Exento', 'wc-afip-facturacion'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wcafip_domicilio"><?php _e('Domicilio Fiscal', 'wc-afip-facturacion'); ?></label></th>
                        <td><input type="text" name="wcafip_domicilio" id="wcafip_domicilio" value="<?php echo esc_attr(get_option('wcafip_domicilio')); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><label for="wcafip_ingresos_brutos"><?php _e('Ingresos Brutos', 'wc-afip-facturacion'); ?></label></th>
                        <td><input type="text" name="wcafip_ingresos_brutos" id="wcafip_ingresos_brutos" value="<?php echo esc_attr(get_option('wcafip_ingresos_brutos')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="wcafip_inicio_actividades"><?php _e('Inicio de Actividades', 'wc-afip-facturacion'); ?></label></th>
                        <td><input type="text" name="wcafip_inicio_actividades" id="wcafip_inicio_actividades" value="<?php echo esc_attr(get_option('wcafip_inicio_actividades')); ?>" class="regular-text" placeholder="01/01/2020"></td>
                    </tr>
                    <tr>
                        <th><label for="wcafip_punto_venta"><?php _e('Punto de Venta', 'wc-afip-facturacion'); ?></label></th>
                        <td><input type="number" name="wcafip_punto_venta" id="wcafip_punto_venta" value="<?php echo esc_attr(get_option('wcafip_punto_venta', 1)); ?>" class="small-text" min="1" max="99999"></td>
                    </tr>
                </table>
                
                <!-- AFIP -->
                <h2><?php _e('Configuración AFIP', 'wc-afip-facturacion'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="wcafip_ambiente"><?php _e('Ambiente', 'wc-afip-facturacion'); ?></label></th>
                        <td>
                            <select name="wcafip_ambiente" id="wcafip_ambiente">
                                <option value="testing" <?php selected(get_option('wcafip_ambiente', 'testing'), 'testing'); ?>><?php _e('Homologación (Testing)', 'wc-afip-facturacion'); ?></option>
                                <option value="production" <?php selected(get_option('wcafip_ambiente'), 'production'); ?>><?php _e('Producción', 'wc-afip-facturacion'); ?></option>
                            </select>
                            <p class="description"><?php _e('Usa Homologación para pruebas antes de pasar a Producción.', 'wc-afip-facturacion'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Certificado (.crt)', 'wc-afip-facturacion'); ?></label></th>
                        <td>
                            <input type="file" name="wcafip_cert_upload" accept=".crt,.pem">
                            <?php 
                            $cert_file = get_option('wcafip_cert_file');
                            if ($cert_file && file_exists($certs_dir . '/' . $cert_file)): ?>
                                <p class="description">
                                    <span class="dashicons dashicons-yes" style="color: green;"></span>
                                    <?php echo esc_html($cert_file); ?>
                                </p>
                            <?php else: ?>
                                <p class="description" style="color: red;">
                                    <span class="dashicons dashicons-no"></span>
                                    <?php _e('No hay certificado cargado', 'wc-afip-facturacion'); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Clave Privada (.key)', 'wc-afip-facturacion'); ?></label></th>
                        <td>
                            <input type="file" name="wcafip_key_upload" accept=".key,.pem">
                            <?php 
                            $key_file = get_option('wcafip_key_file');
                            if ($key_file && file_exists($certs_dir . '/' . $key_file)): ?>
                                <p class="description">
                                    <span class="dashicons dashicons-yes" style="color: green;"></span>
                                    <?php echo esc_html($key_file); ?>
                                </p>
                            <?php else: ?>
                                <p class="description" style="color: red;">
                                    <span class="dashicons dashicons-no"></span>
                                    <?php _e('No hay clave cargada', 'wc-afip-facturacion'); ?>
                                    <?php if ($key_file): ?>
                                        <br><small>Archivo esperado: <?php echo esc_html($key_file); ?> (no encontrado)</small>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Diagnóstico', 'wc-afip-facturacion'); ?></th>
                        <td>
                            <?php
                            echo '<div style="background: #f5f5f5; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;">';
                            echo '<strong>Directorio de certificados:</strong><br>';
                            echo esc_html($certs_dir) . '<br><br>';
                            
                            if (file_exists($certs_dir)) {
                                echo '<strong>Archivos encontrados:</strong><br>';
                                $files = scandir($certs_dir);
                                $cert_files = array_filter($files, function($f) {
                                    return !in_array($f, array('.', '..', '.htaccess', 'index.php')) && 
                                           (strpos($f, '.crt') !== false || strpos($f, '.key') !== false || strpos($f, '.pem') !== false || strpos($f, '.wsdl') !== false);
                                });
                                
                                if (empty($cert_files)) {
                                    echo '<span style="color: red;">No hay certificados</span><br>';
                                } else {
                                    foreach ($cert_files as $file) {
                                        $filepath = $certs_dir . '/' . $file;
                                        $size = filesize($filepath);
                                        $readable = is_readable($filepath) ? '✓' : '✗';
                                        echo "- {$file} ({$size} bytes) [{$readable}]<br>";
                                    }
                                }
                            } else {
                                echo '<span style="color: red;">El directorio no existe</span>';
                            }
                            
                            echo '<br><strong>Configuración guardada:</strong><br>';
                            echo 'Certificado: ' . esc_html(get_option('wcafip_cert_file', '(no configurado)')) . '<br>';
                            echo 'Clave: ' . esc_html(get_option('wcafip_key_file', '(no configurado)')) . '<br>';
                            echo '</div>';
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Probar Conexión', 'wc-afip-facturacion'); ?></th>
                        <td>
                            <button type="button" class="button" id="wcafip-test-connection"><?php _e('Probar Conexión con AFIP', 'wc-afip-facturacion'); ?></button>
                            <span id="wcafip-test-result"></span>
                        </td>
                    </tr>
                </table>
                
                <!-- Facturación -->
                <h2><?php _e('Configuración de Facturación', 'wc-afip-facturacion'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="wcafip_permitir_factura_a"><?php _e('Permitir Factura A (CUIT)', 'wc-afip-facturacion'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wcafip_permitir_factura_a" id="wcafip_permitir_factura_a" value="yes" <?php checked(get_option('wcafip_permitir_factura_a', 'yes'), 'yes'); ?>>
                                <?php _e('Permitir que el cliente ingrese CUIT para recibir Factura A', 'wc-afip-facturacion'); ?>
                            </label>
                            <p class="description"><?php _e('Si se desactiva, solo se mostrará el campo DNI y se emitirá Factura B o C según tu condición fiscal.', 'wc-afip-facturacion'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wcafip_facturacion_automatica"><?php _e('Facturación Automática', 'wc-afip-facturacion'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wcafip_facturacion_automatica" id="wcafip_facturacion_automatica" value="yes" <?php checked(get_option('wcafip_facturacion_automatica'), 'yes'); ?>>
                                <?php _e('Emitir factura automáticamente cuando el pedido cambie de estado', 'wc-afip-facturacion'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Estados que disparan facturación', 'wc-afip-facturacion'); ?></label></th>
                        <td>
                            <?php
                            $estados_guardados = get_option('wcafip_estados_facturar', array('completed', 'processing'));
                            $estados = wc_get_order_statuses();
                            foreach ($estados as $slug => $nombre):
                                $slug_clean = str_replace('wc-', '', $slug);
                            ?>
                                <label style="display: block; margin-bottom: 5px;">
                                    <input type="checkbox" name="wcafip_estados_facturar[]" value="<?php echo esc_attr($slug_clean); ?>" <?php checked(in_array($slug_clean, $estados_guardados)); ?>>
                                    <?php echo esc_html($nombre); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wcafip_enviar_email"><?php _e('Enviar Email', 'wc-afip-facturacion'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wcafip_enviar_email" id="wcafip_enviar_email" value="yes" <?php checked(get_option('wcafip_enviar_email', 'yes'), 'yes'); ?>>
                                <?php _e('Enviar factura por email al cliente', 'wc-afip-facturacion'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#wcafip-test-connection').on('click', function() {
                var $btn = $(this);
                var $result = $('#wcafip-test-result');
                
                $btn.prop('disabled', true);
                $result.html('<span class="spinner is-active" style="float: none;"></span>');
                
                $.post(ajaxurl, {
                    action: 'wcafip_test_connection',
                    nonce: '<?php echo wp_create_nonce('wcafip_test'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Guardar configuración
     */
    private function save_settings() {
        // Guardar opciones de texto
        $text_options = array(
            'wcafip_razon_social', 'wcafip_cuit', 'wcafip_condicion_iva', 
            'wcafip_domicilio', 'wcafip_ingresos_brutos', 'wcafip_inicio_actividades',
            'wcafip_punto_venta', 'wcafip_ambiente'
        );
        
        foreach ($text_options as $option) {
            if (isset($_POST[$option])) {
                update_option($option, sanitize_text_field($_POST[$option]));
            }
        }
        
        // Checkboxes
        update_option('wcafip_permitir_factura_a', isset($_POST['wcafip_permitir_factura_a']) ? 'yes' : 'no');
        update_option('wcafip_facturacion_automatica', isset($_POST['wcafip_facturacion_automatica']) ? 'yes' : 'no');
        update_option('wcafip_enviar_email', isset($_POST['wcafip_enviar_email']) ? 'yes' : 'no');
        
        // Estados
        $estados = isset($_POST['wcafip_estados_facturar']) ? array_map('sanitize_text_field', $_POST['wcafip_estados_facturar']) : array();
        update_option('wcafip_estados_facturar', $estados);
        
        // Subir certificados
        $upload_dir = wp_upload_dir();
        $certs_dir = $upload_dir['basedir'] . '/afip-certs';
        
        if (!empty($_FILES['wcafip_cert_upload']['name'])) {
            $filename = 'certificado_' . time() . '.crt';
            if (move_uploaded_file($_FILES['wcafip_cert_upload']['tmp_name'], $certs_dir . '/' . $filename)) {
                update_option('wcafip_cert_file', $filename);
            }
        }
        
        if (!empty($_FILES['wcafip_key_upload']['name'])) {
            $filename = 'clave_' . time() . '.key';
            if (move_uploaded_file($_FILES['wcafip_key_upload']['tmp_name'], $certs_dir . '/' . $filename)) {
                update_option('wcafip_key_file', $filename);
            }
        }
        
        // Invalidar ticket si cambió el CUIT
        $wsaa = new WCAFIP_WSAA();
        $wsaa->invalidate_ticket();
        
        add_settings_error('wcafip_messages', 'wcafip_message', __('Configuración guardada.', 'wc-afip-facturacion'), 'updated');
        settings_errors('wcafip_messages');
    }
    
    /**
     * AJAX: Probar conexión
     */
    public function ajax_test_connection() {
        check_ajax_referer('wcafip_test', 'nonce');
        
        // Primero verificar requisitos del servidor
        $requisitos = $this->verificar_requisitos();
        if (!$requisitos['ok']) {
            wp_send_json_error(array(
                'message' => $requisitos['error']
            ));
            return;
        }
        
        try {
            $wsfe = new WCAFIP_WSFE();
            $status = $wsfe->server_status();
            
            if (isset($status['success']) && !$status['success']) {
                wp_send_json_error(array(
                    'message' => $status['error']
                ));
                return;
            }
            
            if ($status['app_server'] === 'OK') {
                wp_send_json_success(array(
                    'message' => sprintf(
                        __('Conexión exitosa. App: %s | DB: %s | Auth: %s', 'wc-afip-facturacion'),
                        $status['app_server'],
                        $status['db_server'],
                        $status['auth_server']
                    )
                ));
            } else {
                wp_send_json_error(array(
                    'message' => __('Servidores AFIP con problemas. Intentá más tarde.', 'wc-afip-facturacion')
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }
    
    /**
     * Verificar requisitos del servidor
     */
    private function verificar_requisitos() {
        // Verificar extensión SOAP
        if (!extension_loaded('soap')) {
            return array(
                'ok' => false,
                'error' => __('La extensión SOAP de PHP no está instalada. Contactá a tu proveedor de hosting.', 'wc-afip-facturacion')
            );
        }
        
        // Verificar extensión OpenSSL
        if (!extension_loaded('openssl')) {
            return array(
                'ok' => false,
                'error' => __('La extensión OpenSSL de PHP no está instalada.', 'wc-afip-facturacion')
            );
        }
        
        // Verificar extensión cURL
        if (!extension_loaded('curl')) {
            return array(
                'ok' => false,
                'error' => __('La extensión cURL de PHP no está instalada.', 'wc-afip-facturacion')
            );
        }
        
        // Verificar que puede conectarse a AFIP con configuración SSL especial
        $test_url = get_option('wcafip_ambiente', 'testing') === 'production' 
            ? 'https://servicios1.afip.gov.ar' 
            : 'https://wswhomo.afip.gov.ar';
            
        $ch = curl_init($test_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 30,
            // Configuración especial para AFIP - resolver problema de DH key too small
            CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2
        ));
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error) {
            // Si falla con el cipher list, dar instrucciones específicas
            if (strpos($error, 'dh key too small') !== false) {
                return array(
                    'ok' => false,
                    'error' => __('Error de SSL con AFIP. Tu servidor necesita configuración especial. Contactá a tu hosting para que ajusten OpenSSL o usá el parche automático del plugin.', 'wc-afip-facturacion')
                );
            }
            
            return array(
                'ok' => false,
                'error' => sprintf(
                    __('No se puede conectar con AFIP: %s. Verificá que tu servidor permita conexiones salientes HTTPS.', 'wc-afip-facturacion'),
                    $error
                )
            );
        }
        
        return array('ok' => true);
    }
}
