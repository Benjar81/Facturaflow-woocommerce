<?php
/**
 * Clase License - Gestión de licencias del plugin
 *
 * @package WC_AFIP_Facturacion
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAFIP_License {

    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;

    /**
     * URLs de la API de licencias
     */
    const LICENSE_API_URL = 'https://facturaflow.net/api/license';

    /**
     * Nombre de la opción de licencia
     */
    const LICENSE_KEY_OPTION = 'wcafip_license_key';
    const LICENSE_STATUS_OPTION = 'wcafip_license_status';
    const LICENSE_DATA_OPTION = 'wcafip_license_data';
    const LICENSE_LAST_CHECK_OPTION = 'wcafip_license_last_check';

    /**
     * Obtener instancia
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Registrar hooks de administración
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'license_admin_notices'));
        add_action('wp_ajax_wcafip_activate_license', array($this, 'ajax_activate_license'));
        add_action('wp_ajax_wcafip_deactivate_license', array($this, 'ajax_deactivate_license'));
        add_action('wp_ajax_wcafip_check_license', array($this, 'ajax_check_license'));

        // Verificar licencia periódicamente
        add_action('wcafip_license_check_event', array($this, 'scheduled_license_check'));

        // Programar verificación diaria si no existe
        if (!wp_next_scheduled('wcafip_license_check_event')) {
            wp_schedule_event(time(), 'daily', 'wcafip_license_check_event');
        }
    }

    /**
     * Registrar settings
     */
    public function register_settings() {
        register_setting('wcafip-license', self::LICENSE_KEY_OPTION, array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
    }

    /**
     * Verificar si la licencia es válida (con verificación periódica cada hora)
     */
    public function is_license_valid() {
        $status = get_option(self::LICENSE_STATUS_OPTION, 'inactive');

        if ($status === 'active') {
            $last_check = get_option(self::LICENSE_LAST_CHECK_OPTION, 0);
            $now = time();

            // Verificar cada 1 hora
            if (($now - $last_check) > HOUR_IN_SECONDS) {
                $this->verify_license_remote();
                $status = get_option(self::LICENSE_STATUS_OPTION, 'inactive');
            }
        }

        return $status === 'active';
    }

    /**
     * Verificar licencia con el servidor (sin cache)
     *
     * @return bool True si la licencia es válida
     */
    public function verify_license_remote() {
        $license_key = $this->get_license_key();

        if (empty($license_key)) {
            update_option(self::LICENSE_STATUS_OPTION, 'inactive');
            return false;
        }

        $response = $this->api_request('check', array(
            'license_key' => $license_key,
            'domain' => $this->get_site_domain()
        ));

        if (is_wp_error($response)) {
            // En caso de error de conexión, mantener el estado actual
            // pero actualizar el timestamp para no reintentar inmediatamente
            update_option(self::LICENSE_LAST_CHECK_OPTION, time());
            return get_option(self::LICENSE_STATUS_OPTION, 'inactive') === 'active';
        }

        // Verificar respuesta
        $is_valid = !empty($response['valid']) || !empty($response['success']);

        if (!$is_valid) {
            // Licencia inválida, expirada o eliminada
            update_option(self::LICENSE_STATUS_OPTION, 'inactive');
            return false;
        }

        // Actualizar datos locales
        update_option(self::LICENSE_STATUS_OPTION, 'active');
        update_option(self::LICENSE_LAST_CHECK_OPTION, time());

        if (!empty($response['data'])) {
            update_option(self::LICENSE_DATA_OPTION, $response['data']);
        }

        return true;
    }

    /**
     * Obtener la clave de licencia
     */
    public function get_license_key() {
        return get_option(self::LICENSE_KEY_OPTION, '');
    }

    /**
     * Obtener datos de la licencia
     */
    public function get_license_data() {
        return get_option(self::LICENSE_DATA_OPTION, array());
    }

    /**
     * Obtener el dominio del sitio (sin protocolo)
     */
    private function get_site_domain() {
        $site_url = home_url();
        $parsed = parse_url($site_url);
        return isset($parsed['host']) ? $parsed['host'] : $site_url;
    }

    /**
     * Activar licencia
     */
    public function activate_license($license_key) {
        $license_key = sanitize_text_field(trim($license_key));

        if (empty($license_key)) {
            return array(
                'success' => false,
                'message' => __('Por favor, ingresa una clave de licencia válida.', 'wc-afip-facturacion')
            );
        }

        // Obtener dominio del sitio
        $domain = $this->get_site_domain();

        // Realizar solicitud a la API
        $response = $this->api_request('activate', array(
            'license_key' => $license_key,
            'domain' => $domain,
            'plugin_version' => WCAFIP_VERSION
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }

        if (!empty($response['success']) && $response['success']) {
            // Guardar datos de licencia (usar 'data' de la respuesta)
            update_option(self::LICENSE_KEY_OPTION, $license_key);
            update_option(self::LICENSE_STATUS_OPTION, 'active');
            update_option(self::LICENSE_DATA_OPTION, $response['data'] ?? array());
            update_option(self::LICENSE_LAST_CHECK_OPTION, time());

            return array(
                'success' => true,
                'message' => $response['message'] ?? __('Licencia activada correctamente.', 'wc-afip-facturacion'),
                'data' => $response['data'] ?? array()
            );
        }

        return array(
            'success' => false,
            'message' => $response['message'] ?? __('Error al activar la licencia. Verifica que sea válida.', 'wc-afip-facturacion')
        );
    }

    /**
     * Desactivar licencia
     */
    public function deactivate_license() {
        $license_key = $this->get_license_key();

        if (empty($license_key)) {
            return array(
                'success' => false,
                'message' => __('No hay licencia para desactivar.', 'wc-afip-facturacion')
            );
        }

        // Realizar solicitud a la API
        $response = $this->api_request('deactivate', array(
            'license_key' => $license_key,
            'domain' => $this->get_site_domain()
        ));

        // Limpiar datos locales independientemente de la respuesta
        delete_option(self::LICENSE_KEY_OPTION);
        update_option(self::LICENSE_STATUS_OPTION, 'inactive');
        delete_option(self::LICENSE_DATA_OPTION);
        delete_option(self::LICENSE_LAST_CHECK_OPTION);

        if (is_wp_error($response)) {
            return array(
                'success' => true,
                'message' => __('Licencia desactivada localmente.', 'wc-afip-facturacion')
            );
        }

        return array(
            'success' => true,
            'message' => $response['message'] ?? __('Licencia desactivada correctamente.', 'wc-afip-facturacion')
        );
    }

    /**
     * Verificar estado de la licencia
     */
    public function check_license() {
        $license_key = $this->get_license_key();

        if (empty($license_key)) {
            update_option(self::LICENSE_STATUS_OPTION, 'inactive');
            return array(
                'success' => false,
                'status' => 'inactive',
                'message' => __('No hay licencia configurada.', 'wc-afip-facturacion')
            );
        }

        $response = $this->api_request('check', array(
            'license_key' => $license_key,
            'domain' => $this->get_site_domain()
        ));

        if (is_wp_error($response)) {
            // En caso de error de conexión, mantener el estado actual
            return array(
                'success' => false,
                'status' => get_option(self::LICENSE_STATUS_OPTION, 'inactive'),
                'message' => $response->get_error_message()
            );
        }

        // Verificar respuesta (puede ser 'valid' o 'success')
        $is_valid = !empty($response['valid']) || !empty($response['success']);
        $status = $is_valid ? 'active' : 'inactive';
        update_option(self::LICENSE_STATUS_OPTION, $status);
        update_option(self::LICENSE_LAST_CHECK_OPTION, time());

        if (!empty($response['data'])) {
            update_option(self::LICENSE_DATA_OPTION, $response['data']);
        }

        return array(
            'success' => $is_valid,
            'status' => $status,
            'message' => $response['message'] ?? '',
            'data' => $response['data'] ?? array()
        );
    }

    /**
     * Verificación programada de licencia
     */
    public function scheduled_license_check() {
        $this->check_license();
    }

    /**
     * Realizar solicitud a la API
     */
    private function api_request($action, $data) {
        $url = self::LICENSE_API_URL . '/' . $action;

        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($data)
        ));

        if (is_wp_error($response)) {
            return new WP_Error(
                'api_error',
                __('Error de conexión con el servidor de licencias. Por favor, intenta más tarde.', 'wc-afip-facturacion')
            );
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        // Si el servidor responde con 200 o 201, usar esta respuesta
        if ($http_code === 200 || $http_code === 201) {
            return $result;
        }

        // Para cualquier otro código, obtener el mensaje de error
        $error_message = null;
        if (is_array($result) && isset($result['message'])) {
            $error_message = $result['message'];
        } elseif (is_array($result) && isset($result['error'])) {
            $error_message = $result['error'];
        } else {
            $error_message = sprintf(__('Error del servidor: %s', 'wc-afip-facturacion'), $http_code);
        }

        return new WP_Error('api_error', $error_message);
    }

    /**
     * Mostrar avisos de administración
     */
    public function license_admin_notices() {
        // Solo mostrar en páginas del plugin
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'wcafip') === false) {
            // También mostrar en el dashboard
            if ($screen && $screen->id !== 'dashboard') {
                return;
            }
        }

        if (!$this->is_license_valid()) {
            $license_url = admin_url('admin.php?page=wcafip-license');
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php _e('WooCommerce AFIP Facturación', 'wc-afip-facturacion'); ?>:</strong>
                    <?php _e('Se requiere una licencia válida para usar este plugin.', 'wc-afip-facturacion'); ?>
                    <a href="<?php echo esc_url($license_url); ?>"><?php _e('Activar licencia', 'wc-afip-facturacion'); ?></a> |
                    <a href="https://facturaflow.net" target="_blank"><?php _e('Obtener licencia', 'wc-afip-facturacion'); ?></a>
                </p>
            </div>
            <?php
        }
    }

    /**
     * AJAX: Activar licencia
     */
    public function ajax_activate_license() {
        check_ajax_referer('wcafip_license_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('No tienes permisos para realizar esta acción.', 'wc-afip-facturacion')
            ));
        }

        $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';
        $result = $this->activate_license($license_key);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Desactivar licencia
     */
    public function ajax_deactivate_license() {
        check_ajax_referer('wcafip_license_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('No tienes permisos para realizar esta acción.', 'wc-afip-facturacion')
            ));
        }

        $result = $this->deactivate_license();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Verificar licencia
     */
    public function ajax_check_license() {
        check_ajax_referer('wcafip_license_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('No tienes permisos para realizar esta acción.', 'wc-afip-facturacion')
            ));
        }

        $result = $this->check_license();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Renderizar página de licencia
     */
    public function render_license_page() {
        $license_key = $this->get_license_key();
        $license_status = get_option(self::LICENSE_STATUS_OPTION, 'inactive');
        $license_data = $this->get_license_data();
        $last_check = get_option(self::LICENSE_LAST_CHECK_OPTION, 0);

        ?>
        <div class="wrap wcafip-license-wrap">
            <h1><?php _e('Licencia del Plugin', 'wc-afip-facturacion'); ?></h1>

            <div class="wcafip-license-box">
                <div class="wcafip-license-header">
                    <img src="<?php echo esc_url(WCAFIP_PLUGIN_URL . 'assets/images/logo.png'); ?>" alt="FacturaFlow" class="wcafip-logo" onerror="this.style.display='none'">
                    <h2><?php _e('WooCommerce AFIP Facturación Electrónica', 'wc-afip-facturacion'); ?></h2>
                </div>

                <div class="wcafip-license-status <?php echo esc_attr($license_status); ?>">
                    <?php if ($license_status === 'active'): ?>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <span><?php _e('Licencia Activa', 'wc-afip-facturacion'); ?></span>
                    <?php else: ?>
                        <span class="dashicons dashicons-warning"></span>
                        <span><?php _e('Licencia Inactiva', 'wc-afip-facturacion'); ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($license_status === 'active' && !empty($license_data)): ?>
                    <div class="wcafip-license-info">
                        <table class="widefat">
                            <tbody>
                                <?php if (!empty($license_data['customer_name'])): ?>
                                <tr>
                                    <th><?php _e('Cliente', 'wc-afip-facturacion'); ?></th>
                                    <td><?php echo esc_html($license_data['customer_name']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if (!empty($license_data['customer_email'])): ?>
                                <tr>
                                    <th><?php _e('Email', 'wc-afip-facturacion'); ?></th>
                                    <td><?php echo esc_html($license_data['customer_email']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if (!empty($license_data['license_type'])): ?>
                                <tr>
                                    <th><?php _e('Tipo de Licencia', 'wc-afip-facturacion'); ?></th>
                                    <td><?php echo esc_html($license_data['license_type']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if (!empty($license_data['expires_at'])): ?>
                                <tr>
                                    <th><?php _e('Vencimiento', 'wc-afip-facturacion'); ?></th>
                                    <td>
                                        <?php
                                        $expires = strtotime($license_data['expires_at']);
                                        if ($expires) {
                                            echo esc_html(date_i18n(get_option('date_format'), $expires));
                                            if ($expires < time()) {
                                                echo ' <span style="color: red;">(' . __('Vencida', 'wc-afip-facturacion') . ')</span>';
                                            }
                                        } else {
                                            echo esc_html($license_data['expires_at']);
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php if (isset($license_data['activations_max']) || isset($license_data['activations_left'])): ?>
                                <tr>
                                    <th><?php _e('Activaciones', 'wc-afip-facturacion'); ?></th>
                                    <td>
                                        <?php
                                        $max = $license_data['activations_max'] ?? 'ilimitado';
                                        $left = $license_data['activations_left'] ?? 0;
                                        if (is_numeric($max)) {
                                            $used = $max - $left;
                                            echo esc_html($used . ' / ' . $max . ' ' . __('usadas', 'wc-afip-facturacion'));
                                        } else {
                                            echo esc_html($max);
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php elseif (!empty($license_data['activations'])): ?>
                                <tr>
                                    <th><?php _e('Sitios Activos', 'wc-afip-facturacion'); ?></th>
                                    <td>
                                        <?php
                                        $used = $license_data['activations']['used'] ?? 0;
                                        $max = $license_data['activations']['max'] ?? 'ilimitado';
                                        echo esc_html($used . ' / ' . $max);
                                        ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($last_check): ?>
                                <tr>
                                    <th><?php _e('Última Verificación', 'wc-afip-facturacion'); ?></th>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_check)); ?></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="wcafip-license-form">
                    <form id="wcafip-license-form">
                        <?php wp_nonce_field('wcafip_license_nonce', 'wcafip_license_nonce'); ?>

                        <table class="form-table">
                            <tr>
                                <th><label for="wcafip_license_key"><?php _e('Clave de Licencia', 'wc-afip-facturacion'); ?></label></th>
                                <td>
                                    <input type="text"
                                           name="wcafip_license_key"
                                           id="wcafip_license_key"
                                           value="<?php echo esc_attr($license_key); ?>"
                                           class="regular-text"
                                           placeholder="XXXX-XXXX-XXXX-XXXX"
                                           <?php echo $license_status === 'active' ? 'readonly' : ''; ?>>

                                    <?php if ($license_status !== 'active'): ?>
                                        <p class="description">
                                            <?php _e('Ingresa tu clave de licencia para activar el plugin.', 'wc-afip-facturacion'); ?>
                                            <a href="https://facturaflow.net" target="_blank"><?php _e('Obtener licencia', 'wc-afip-facturacion'); ?></a>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>

                        <div class="wcafip-license-actions">
                            <?php if ($license_status === 'active'): ?>
                                <button type="button" id="wcafip-check-license" class="button">
                                    <?php _e('Verificar Licencia', 'wc-afip-facturacion'); ?>
                                </button>
                                <button type="button" id="wcafip-deactivate-license" class="button button-secondary">
                                    <?php _e('Desactivar Licencia', 'wc-afip-facturacion'); ?>
                                </button>
                            <?php else: ?>
                                <button type="submit" id="wcafip-activate-license" class="button button-primary">
                                    <?php _e('Activar Licencia', 'wc-afip-facturacion'); ?>
                                </button>
                            <?php endif; ?>

                            <span id="wcafip-license-spinner" class="spinner"></span>
                            <span id="wcafip-license-message"></span>
                        </div>
                    </form>
                </div>

                <div class="wcafip-license-footer">
                    <p>
                        <?php _e('¿No tienes una licencia?', 'wc-afip-facturacion'); ?>
                        <a href="https://facturaflow.net" target="_blank" class="button button-hero">
                            <?php _e('Comprar Licencia en FacturaFlow.net', 'wc-afip-facturacion'); ?>
                        </a>
                    </p>
                    <p class="description">
                        <?php _e('La licencia te permite usar todas las funcionalidades del plugin, recibir actualizaciones y soporte técnico.', 'wc-afip-facturacion'); ?>
                    </p>
                </div>
            </div>
        </div>

        <style>
            .wcafip-license-wrap {
                max-width: 800px;
            }
            .wcafip-license-box {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px 30px;
                margin-top: 20px;
            }
            .wcafip-license-header {
                text-align: center;
                padding-bottom: 20px;
                border-bottom: 1px solid #eee;
                margin-bottom: 20px;
            }
            .wcafip-license-header h2 {
                margin: 10px 0 0;
            }
            .wcafip-logo {
                max-width: 200px;
                height: auto;
            }
            .wcafip-license-status {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 15px;
                border-radius: 4px;
                font-size: 16px;
                font-weight: 600;
                margin-bottom: 20px;
            }
            .wcafip-license-status .dashicons {
                font-size: 24px;
                width: 24px;
                height: 24px;
                margin-right: 10px;
            }
            .wcafip-license-status.active {
                background: #d4edda;
                color: #155724;
            }
            .wcafip-license-status.inactive {
                background: #fff3cd;
                color: #856404;
            }
            .wcafip-license-info {
                margin-bottom: 20px;
            }
            .wcafip-license-info table {
                border: none;
            }
            .wcafip-license-info th {
                width: 150px;
                font-weight: 600;
            }
            .wcafip-license-form {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            .wcafip-license-actions {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-top: 15px;
            }
            .wcafip-license-actions .spinner {
                float: none;
                margin: 0;
            }
            #wcafip-license-message {
                font-weight: 500;
            }
            #wcafip-license-message.success {
                color: #155724;
            }
            #wcafip-license-message.error {
                color: #721c24;
            }
            .wcafip-license-footer {
                text-align: center;
                padding-top: 20px;
                border-top: 1px solid #eee;
            }
            .wcafip-license-footer .button-hero {
                margin: 10px 0;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var $form = $('#wcafip-license-form');
            var $spinner = $('#wcafip-license-spinner');
            var $message = $('#wcafip-license-message');

            function showMessage(text, type) {
                $message.removeClass('success error').addClass(type).text(text);
            }

            function setLoading(loading) {
                if (loading) {
                    $spinner.addClass('is-active');
                    $form.find('button').prop('disabled', true);
                } else {
                    $spinner.removeClass('is-active');
                    $form.find('button').prop('disabled', false);
                }
            }

            // Activar licencia
            $form.on('submit', function(e) {
                e.preventDefault();

                var licenseKey = $('#wcafip_license_key').val().trim();
                if (!licenseKey) {
                    showMessage('<?php echo esc_js(__('Por favor, ingresa una clave de licencia.', 'wc-afip-facturacion')); ?>', 'error');
                    return;
                }

                setLoading(true);
                $message.text('');

                $.post(ajaxurl, {
                    action: 'wcafip_activate_license',
                    nonce: $('#wcafip_license_nonce').val(),
                    license_key: licenseKey
                }, function(response) {
                    setLoading(false);
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showMessage(response.data.message, 'error');
                    }
                }).fail(function() {
                    setLoading(false);
                    showMessage('<?php echo esc_js(__('Error de conexión. Intenta nuevamente.', 'wc-afip-facturacion')); ?>', 'error');
                });
            });

            // Desactivar licencia
            $('#wcafip-deactivate-license').on('click', function() {
                if (!confirm('<?php echo esc_js(__('¿Estás seguro de que deseas desactivar la licencia?', 'wc-afip-facturacion')); ?>')) {
                    return;
                }

                setLoading(true);
                $message.text('');

                $.post(ajaxurl, {
                    action: 'wcafip_deactivate_license',
                    nonce: $('#wcafip_license_nonce').val()
                }, function(response) {
                    setLoading(false);
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showMessage(response.data.message, 'error');
                    }
                }).fail(function() {
                    setLoading(false);
                    showMessage('<?php echo esc_js(__('Error de conexión. Intenta nuevamente.', 'wc-afip-facturacion')); ?>', 'error');
                });
            });

            // Verificar licencia
            $('#wcafip-check-license').on('click', function() {
                setLoading(true);
                $message.text('');

                $.post(ajaxurl, {
                    action: 'wcafip_check_license',
                    nonce: $('#wcafip_license_nonce').val()
                }, function(response) {
                    setLoading(false);
                    if (response.success) {
                        showMessage('<?php echo esc_js(__('Licencia válida.', 'wc-afip-facturacion')); ?>', 'success');
                    } else {
                        showMessage(response.data.message || '<?php echo esc_js(__('Licencia inválida.', 'wc-afip-facturacion')); ?>', 'error');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    }
                }).fail(function() {
                    setLoading(false);
                    showMessage('<?php echo esc_js(__('Error de conexión. Intenta nuevamente.', 'wc-afip-facturacion')); ?>', 'error');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Limpiar eventos programados al desactivar
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('wcafip_license_check_event');
    }
}
