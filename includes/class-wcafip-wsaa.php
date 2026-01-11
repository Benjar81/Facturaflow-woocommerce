<?php
/**
 * Clase WSAA - Autenticación con AFIP
 * 
 * @package WC_AFIP_Facturacion
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAFIP_WSAA {
    
    private $cuit;
    private $cert_path;
    private $key_path;
    private $service = 'wsfe';
    private $logger;
    
    // URLs de AFIP
    const WSAA_URL_TESTING = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?WSDL';
    const WSAA_URL_PRODUCTION = 'https://wsaa.afip.gov.ar/ws/services/LoginCms?WSDL';
    
    public function __construct() {
        $this->logger = WCAFIP_Logger::get_instance();
        $this->load_config();
    }
    
    /**
     * Cargar configuración
     */
    private function load_config() {
        // Obtener CUIT y limpiar guiones/espacios
        $cuit_raw = get_option('wcafip_cuit', '');
        $this->cuit = preg_replace('/[^0-9]/', '', $cuit_raw); // Solo números
        
        $upload_dir = wp_upload_dir();
        $certs_dir = $upload_dir['basedir'] . '/afip-certs';
        
        $cert_file = get_option('wcafip_cert_file', '');
        $key_file = get_option('wcafip_key_file', '');
        
        $this->cert_path = $cert_file ? $certs_dir . '/' . $cert_file : '';
        $this->key_path = $key_file ? $certs_dir . '/' . $key_file : '';
        
        // Log para debugging
        $this->logger->info('Configuración WSAA cargada', null, array(
            'cuit' => $this->cuit,
            'cert_path' => $this->cert_path,
            'key_path' => $this->key_path,
            'cert_exists' => file_exists($this->cert_path) ? 'Sí' : 'No',
            'key_exists' => file_exists($this->key_path) ? 'Sí' : 'No'
        ));
    }
    
    /**
     * Obtener URL según ambiente
     */
    private function get_wsaa_url() {
        $env = get_option('wcafip_ambiente', 'testing');
        return $env === 'production' ? self::WSAA_URL_PRODUCTION : self::WSAA_URL_TESTING;
    }
    
    /**
     * Obtener ticket de autenticación
     */
    public function get_ticket() {
        global $wpdb;
        
        // Buscar ticket en caché
        $table = $wpdb->prefix . 'afip_tickets';
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE cuit = %s AND expiracion > NOW()",
            $this->cuit
        ));
        
        if ($ticket) {
            $this->logger->info('Usando ticket en caché para CUIT ' . $this->cuit);
            return array(
                'token' => $ticket->token,
                'sign' => $ticket->sign
            );
        }
        
        // Generar nuevo ticket
        return $this->generate_new_ticket();
    }
    
    /**
     * Generar nuevo ticket de autenticación
     */
    private function generate_new_ticket() {
        try {
            // Verificar certificados
            if (empty($this->cert_path) || empty($this->key_path)) {
                throw new Exception('No hay certificados configurados. Por favor, suba los certificados en la configuración del plugin.');
            }
            
            if (!file_exists($this->cert_path)) {
                throw new Exception('El archivo de certificado no existe: ' . basename($this->cert_path) . '. Por favor, vuelva a subir el certificado.');
            }
            
            if (!file_exists($this->key_path)) {
                throw new Exception('El archivo de clave privada no existe: ' . basename($this->key_path) . '. Por favor, vuelva a subir la clave.');
            }
            
            if (!is_readable($this->cert_path)) {
                throw new Exception('No hay permisos para leer el certificado. Verificá los permisos del archivo.');
            }
            
            if (!is_readable($this->key_path)) {
                throw new Exception('No hay permisos para leer la clave privada. Verificá los permisos del archivo.');
            }
            
            // Crear TRA
            $tra = $this->create_tra();
            
            // Firmar TRA
            $cms = $this->sign_tra($tra);
            
            // Llamar a WSAA
            $response = $this->call_wsaa($cms);
            
            // Parsear respuesta
            $credentials = $this->parse_response($response);
            
            // Guardar en caché
            $this->save_ticket($credentials);
            
            $this->logger->info('Nuevo ticket generado para CUIT ' . $this->cuit);
            
            return array(
                'token' => $credentials['token'],
                'sign' => $credentials['sign']
            );
            
        } catch (Exception $e) {
            $this->logger->error('Error generando ticket WSAA: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Crear TRA (Ticket Request Access)
     */
    private function create_tra() {
        $now = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
        $generation_time = clone $now;
        $generation_time->modify('-10 minutes');
        $expiration_time = clone $now;
        $expiration_time->modify('+10 minutes');
        
        $unique_id = time();
        
        $tra = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $tra .= '<loginTicketRequest version="1.0">' . "\n";
        $tra .= '  <header>' . "\n";
        $tra .= '    <uniqueId>' . $unique_id . '</uniqueId>' . "\n";
        $tra .= '    <generationTime>' . $generation_time->format('c') . '</generationTime>' . "\n";
        $tra .= '    <expirationTime>' . $expiration_time->format('c') . '</expirationTime>' . "\n";
        $tra .= '  </header>' . "\n";
        $tra .= '  <service>' . $this->service . '</service>' . "\n";
        $tra .= '</loginTicketRequest>';
        
        return $tra;
    }
    
    /**
     * Firmar TRA con certificado
     */
    private function sign_tra($tra) {
        // Leer certificado y clave
        $cert = @file_get_contents($this->cert_path);
        $key = @file_get_contents($this->key_path);
        
        if (!$cert) {
            $this->logger->error('No se pudo leer el certificado: ' . $this->cert_path);
            throw new Exception('No se pudo leer el certificado. Verificá que el archivo exista y tenga permisos de lectura. Ruta: ' . $this->cert_path);
        }
        
        if (!$key) {
            $this->logger->error('No se pudo leer la clave privada: ' . $this->key_path);
            throw new Exception('No se pudo leer la clave privada. Verificá que el archivo exista y tenga permisos de lectura. Ruta: ' . $this->key_path);
        }
        
        // Verificar que el certificado sea válido
        $cert_parsed = @openssl_x509_parse($cert);
        if (!$cert_parsed) {
            $this->logger->error('El certificado no es válido o está corrupto');
            throw new Exception('El certificado no es válido. Asegurate de subir el archivo .crt correcto de AFIP.');
        }
        
        // Verificar que la clave privada sea válida
        $key_resource = @openssl_pkey_get_private($key);
        if (!$key_resource) {
            // Intentar sin passphrase
            $key_resource = @openssl_pkey_get_private(array($key, ''));
            if (!$key_resource) {
                $this->logger->error('La clave privada no es válida o está corrupta. Error OpenSSL: ' . openssl_error_string());
                throw new Exception('La clave privada no es válida. Asegurate de subir el archivo .key correcto. Si tiene contraseña, el plugin no la soporta actualmente.');
            }
        }
        
        // Crear archivo temporal para el TRA
        $tra_file = tempnam(sys_get_temp_dir(), 'tra_');
        if (!$tra_file || !file_put_contents($tra_file, $tra)) {
            throw new Exception('No se pudo crear archivo temporal para firmar');
        }
        
        // Archivo temporal para el CMS firmado
        $cms_file = tempnam(sys_get_temp_dir(), 'cms_');
        
        // Firmar con OpenSSL
        $result = @openssl_pkcs7_sign(
            $tra_file,
            $cms_file,
            $cert,
            array($key, ''),
            array(),
            PKCS7_BINARY | PKCS7_NOATTR
        );
        
        if (!$result) {
            @unlink($tra_file);
            @unlink($cms_file);
            $ssl_error = openssl_error_string();
            $this->logger->error('Error al firmar TRA: ' . $ssl_error);
            throw new Exception('Error al firmar el TRA con OpenSSL: ' . $ssl_error);
        }
        
        // Leer CMS firmado
        $cms = @file_get_contents($cms_file);
        
        // Limpiar archivos temporales
        @unlink($tra_file);
        @unlink($cms_file);
        
        if (!$cms) {
            throw new Exception('Error al leer el archivo firmado');
        }
        
        // Extraer solo la parte firmada (sin headers)
        $cms_parts = explode("\n\n", $cms);
        if (count($cms_parts) < 2) {
            throw new Exception('Formato de CMS inválido después de firmar');
        }
        
        // Obtener el contenido base64 sin saltos de línea
        $cms_content = str_replace("\n", '', $cms_parts[1]);
        $cms_content = str_replace("-----END PKCS7-----", '', $cms_content);
        
        return trim($cms_content);
    }
    
    /**
     * Llamar a WSAA
     */
    private function call_wsaa($cms) {
        $wsaa_url = $this->get_wsaa_url();
        
        // Configuración SSL especial para AFIP - resolver "dh key too small"
        $context_options = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'ciphers' => 'DEFAULT@SECLEVEL=1'
            )
        );
        
        $context = stream_context_create($context_options);
        
        try {
            // Primero descargar el WSDL con cURL
            $wsdl_content = $this->download_wsdl_wsaa($wsaa_url);
            
            if ($wsdl_content) {
                $upload_dir = wp_upload_dir();
                $wsdl_dir = $upload_dir['basedir'] . '/afip-certs';
                if (!file_exists($wsdl_dir)) {
                    wp_mkdir_p($wsdl_dir);
                }
                $wsdl_file = $wsdl_dir . '/wsaa_' . md5($wsaa_url) . '.wsdl';
                file_put_contents($wsdl_file, $wsdl_content);
                
                $client = new SoapClient($wsdl_file, array(
                    'soap_version' => SOAP_1_2,
                    'trace' => true,
                    'exceptions' => true,
                    'stream_context' => $context,
                    'cache_wsdl' => WSDL_CACHE_NONE,
                    'location' => str_replace('?WSDL', '', $wsaa_url)
                ));
            } else {
                throw new Exception('No se pudo descargar el WSDL de WSAA');
            }
            
            $response = $client->loginCms(array('in0' => $cms));
            
            return $response->loginCmsReturn;
            
        } catch (SoapFault $e) {
            throw new Exception('Error SOAP WSAA: ' . $e->getMessage());
        }
    }
    
    /**
     * Descargar WSDL de WSAA con cURL
     */
    private function download_wsdl_wsaa($url) {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTPHEADER => array(
                'Accept: text/xml,application/xml',
                'Cache-Control: no-cache'
            )
        ));
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        
        if ($errno || $error) {
            $this->logger->error("Error cURL descargando WSDL WSAA: [$errno] $error");
            
            // Intentar con file_get_contents
            $context = stream_context_create(array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'ciphers' => 'DEFAULT@SECLEVEL=1'
                )
            ));
            
            $response = @file_get_contents($url, false, $context);
            
            if (!$response) {
                // Usar WSDL embebido como último recurso
                return $this->get_embedded_wsaa_wsdl();
            }
        }
        
        if ($response && (strpos($response, '<?xml') !== false || strpos($response, '<definitions') !== false)) {
            return $response;
        }
        
        return $this->get_embedded_wsaa_wsdl();
    }
    
    /**
     * Obtener WSDL embebido de WSAA como último recurso
     */
    private function get_embedded_wsaa_wsdl() {
        $env = get_option('wcafip_ambiente', 'testing');
        
        $location = $env === 'production' 
            ? 'https://wsaa.afip.gov.ar/ws/services/LoginCms'
            : 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
        
        $this->logger->warning("Usando WSDL WSAA embebido para: $location");
        
        $wsdl = '<?xml version="1.0" encoding="UTF-8"?>
<definitions xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/" 
             xmlns:tns="http://wsaa.view.sua.dvadac.desein.afip.gov" 
             xmlns:s="http://www.w3.org/2001/XMLSchema" 
             xmlns="http://schemas.xmlsoap.org/wsdl/" 
             targetNamespace="http://wsaa.view.sua.dvadac.desein.afip.gov" 
             name="LoginCms">
  <types>
    <s:schema elementFormDefault="qualified" targetNamespace="http://wsaa.view.sua.dvadac.desein.afip.gov">
      <s:element name="loginCms">
        <s:complexType><s:sequence>
          <s:element name="in0" type="s:string" minOccurs="0"/>
        </s:sequence></s:complexType>
      </s:element>
      <s:element name="loginCmsResponse">
        <s:complexType><s:sequence>
          <s:element name="loginCmsReturn" type="s:string" minOccurs="0"/>
        </s:sequence></s:complexType>
      </s:element>
    </s:schema>
  </types>
  <message name="loginCmsRequest"><part name="parameters" element="tns:loginCms"/></message>
  <message name="loginCmsResponse"><part name="parameters" element="tns:loginCmsResponse"/></message>
  <portType name="LoginCms">
    <operation name="loginCms"><input message="tns:loginCmsRequest"/><output message="tns:loginCmsResponse"/></operation>
  </portType>
  <binding name="LoginCmsSoapBinding" type="tns:LoginCms">
    <soap:binding style="document" transport="http://schemas.xmlsoap.org/soap/http"/>
    <operation name="loginCms">
      <soap:operation soapAction=""/>
      <input><soap:body use="literal"/></input>
      <output><soap:body use="literal"/></output>
    </operation>
  </binding>
  <service name="LoginCmsService">
    <port name="LoginCms" binding="tns:LoginCmsSoapBinding">
      <soap:address location="' . $location . '"/>
    </port>
  </service>
</definitions>';
        
        return $wsdl;
    }
    
    /**
     * Parsear respuesta de WSAA
     */
    private function parse_response($response) {
        $xml = simplexml_load_string($response);
        
        if (!$xml) {
            throw new Exception('Respuesta XML inválida de WSAA');
        }
        
        if (isset($xml->header->expirationTime)) {
            return array(
                'token' => (string) $xml->credentials->token,
                'sign' => (string) $xml->credentials->sign,
                'expiration' => (string) $xml->header->expirationTime
            );
        }
        
        throw new Exception('Respuesta de WSAA no contiene credenciales');
    }
    
    /**
     * Guardar ticket en base de datos
     */
    private function save_ticket($credentials) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'afip_tickets';
        
        // Convertir fecha de expiración
        $expiration = new DateTime($credentials['expiration']);
        
        $wpdb->replace($table, array(
            'cuit' => $this->cuit,
            'token' => $credentials['token'],
            'sign' => $credentials['sign'],
            'expiracion' => $expiration->format('Y-m-d H:i:s'),
            'created_at' => current_time('mysql')
        ), array('%s', '%s', '%s', '%s', '%s'));
    }
    
    /**
     * Invalidar ticket en caché
     */
    public function invalidate_ticket() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'afip_tickets';
        $wpdb->delete($table, array('cuit' => $this->cuit), array('%s'));
    }
}
