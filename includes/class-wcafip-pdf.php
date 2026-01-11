<?php
/**
 * Clase PDF - Generación de facturas en PDF (sin dependencias externas)
 * 
 * @package WC_AFIP_Facturacion
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAFIP_PDF {
    
    private $buffer = '';
    private $objects = array();
    private $pages = array();
    private $n = 0;
    private $offsets = array();
    private $page_content = '';
    private $y = 800; // Empezamos arriba (PDF usa coordenadas desde abajo)
    
    public function generar($factura, $order) {
        // Resetear
        $this->buffer = '';
        $this->objects = array();
        $this->n = 0;
        $this->offsets = array();
        $this->page_content = '';
        $this->y = 800;
        
        // Datos
        $razon = get_option('wcafip_razon_social', 'EMPRESA');
        $cuit = get_option('wcafip_cuit', '');
        $cuit_limpio = preg_replace('/[^0-9]/', '', $cuit);
        $cuit_fmt = strlen($cuit_limpio) == 11 ? 
            substr($cuit_limpio,0,2).'-'.substr($cuit_limpio,2,8).'-'.substr($cuit_limpio,10,1) : $cuit_limpio;
        $domicilio = get_option('wcafip_domicilio', '');
        $iibb = get_option('wcafip_ingresos_brutos', $cuit_fmt);
        $inicio = get_option('wcafip_inicio_actividades', '');
        
        $letra = WCAFIP_WSFE::get_letra_comprobante($factura->tipo_comprobante);
        $pv = str_pad($factura->punto_venta, 5, '0', STR_PAD_LEFT);
        $nro = str_pad($factura->numero_comprobante, 8, '0', STR_PAD_LEFT);
        $fecha = date('d/m/Y', strtotime($factura->fecha_emision));
        
        // Contenido de la página
        $this->addText(50, 780, $this->safe($razon), 14, true);
        $this->addText(400, 780, 'FACTURA ' . $letra . ' N' . $pv . '-' . $nro, 12, true);
        
        // Letra grande
        $this->addText(280, 770, $letra, 28, true);
        $this->addRect(270, 755, 40, 35);
        
        $this->addText(50, 755, 'CUIT: ' . $cuit_fmt, 9);
        $this->addText(400, 755, 'Fecha: ' . $fecha, 9);
        
        $this->addText(50, 740, 'IVA RESPONSABLE INSCRIPTO', 9);
        $this->addText(400, 740, 'IIBB: ' . $iibb, 9);
        
        $this->addText(50, 725, $this->safe('Domicilio: ' . $domicilio), 8);
        $this->addText(400, 725, 'Inicio: ' . $inicio, 8);
        
        // Línea
        $this->addLine(50, 710, 550, 710);
        
        // Cliente
        $this->addText(50, 695, 'DATOS DEL CLIENTE', 10, true);
        $this->addText(50, 680, 'Cliente: ' . $this->safe($factura->receptor_nombre), 9);
        
        $doc = !empty($factura->receptor_cuit) ? 'CUIT: ' . $factura->receptor_cuit : 'DNI: ' . $factura->receptor_dni;
        $this->addText(50, 665, $doc, 9);
        
        $cond = $factura->receptor_condicion_iva == 5 ? 'CONSUMIDOR FINAL' : 'RESP. INSCRIPTO';
        $this->addText(50, 650, 'Condicion IVA: ' . $cond, 9);
        
        $this->addText(350, 680, 'Condicion de Venta: Contado', 9);
        $this->addText(350, 665, 'Tipo: Producto', 9);
        
        // Línea
        $this->addLine(50, 635, 550, 635);
        
        // Detalle
        $this->addText(50, 620, 'DETALLE', 10, true);
        
        // Header tabla
        $this->addText(50, 600, 'Cant.', 8, true);
        $this->addText(100, 600, 'Descripcion', 8, true);
        $this->addText(380, 600, 'P.Unit.', 8, true);
        $this->addText(480, 600, 'Subtotal', 8, true);
        
        $this->addLine(50, 595, 550, 595);
        
        // Items
        $y = 580;
        if ($order && is_object($order)) {
            $items = $order->get_items();
            $i = 0;
            foreach ($items as $item) {
                if ($i >= 8) break;
                $q = $item->get_quantity();
                $n = substr($item->get_name(), 0, 40);
                $t = floatval($item->get_total());
                $u = $q > 0 ? $t/$q : $t;
                
                $this->addText(50, $y, number_format($q, 2, ',', '.'), 8);
                $this->addText(100, $y, $this->safe($n), 8);
                $this->addText(380, $y, '$ ' . number_format($u, 2, ',', '.'), 8);
                $this->addText(480, $y, '$ ' . number_format($t, 2, ',', '.'), 8);
                $y -= 15;
                $i++;
            }
        } else {
            $this->addText(50, $y, '1', 8);
            $this->addText(100, $y, 'Producto/Servicio', 8);
            $this->addText(380, $y, '$ ' . number_format($factura->importe_total, 2, ',', '.'), 8);
            $this->addText(480, $y, '$ ' . number_format($factura->importe_total, 2, ',', '.'), 8);
        }
        
        // Totales
        $y -= 30;
        $this->addLine(350, $y + 20, 550, $y + 20);
        $this->addText(350, $y, 'Subtotal Gravado:', 9);
        $this->addText(480, $y, '$ ' . number_format($factura->importe_neto, 2, ',', '.'), 9);
        
        $y -= 20;
        $this->addText(350, $y, 'TOTAL:', 12, true);
        $this->addText(480, $y, '$ ' . number_format($factura->importe_total, 2, ',', '.'), 12, true);
        
        // IVA
        $y -= 30;
        $this->addText(50, $y, 'Ley 27.743 - IVA CONTENIDO: $ ' . number_format($factura->importe_iva, 2, ',', '.'), 8);
        
        // CAE (abajo)
        $this->addLine(50, 120, 550, 120);
        $this->addText(50, 100, 'CAE: ' . $factura->cae, 11, true);
        $this->addText(50, 85, 'Vencimiento CAE: ' . date('d/m/Y', strtotime($factura->cae_fecha_vto)), 9);
        
        // Código de barras
        $cod = $cuit_limpio . str_pad($factura->tipo_comprobante, 3, '0', STR_PAD_LEFT) . 
               $pv . $factura->cae . date('Ymd', strtotime($factura->cae_fecha_vto));
        $s = 0;
        for ($i = 0; $i < strlen($cod); $i++) $s += intval($cod[$i]) * (($i % 2 == 0) ? 1 : 3);
        $v = (10 - ($s % 10)) % 10;
        $this->addText(50, 70, 'Cod: ' . $cod . $v, 7);
        
        // Generar PDF
        $pdf = $this->buildPDF();
        
        // Guardar
        $filename = 'Factura_' . $letra . '_' . $pv . '-' . $nro . '.pdf';
        $upload = wp_upload_dir();
        $dir = $upload['basedir'] . '/afip-facturas/' . date('Y/m');
        
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        
        $filepath = $dir . '/' . $filename;
        file_put_contents($filepath, $pdf);
        
        if (!file_exists($filepath) || filesize($filepath) < 100) {
            throw new Exception('Error al crear PDF');
        }
        
        return array(
            'path' => $filepath,
            'filename' => $filename,
            'url' => $upload['baseurl'] . '/afip-facturas/' . date('Y/m') . '/' . $filename
        );
    }
    
    private function addText($x, $y, $text, $size = 10, $bold = false) {
        $font = $bold ? '/F2' : '/F1';
        $this->page_content .= "BT\n";
        $this->page_content .= "{$font} {$size} Tf\n";
        $this->page_content .= "{$x} {$y} Td\n";
        $this->page_content .= "(" . $this->escapeString($text) . ") Tj\n";
        $this->page_content .= "ET\n";
    }
    
    private function addLine($x1, $y1, $x2, $y2) {
        $this->page_content .= "{$x1} {$y1} m\n";
        $this->page_content .= "{$x2} {$y2} l\n";
        $this->page_content .= "S\n";
    }
    
    private function addRect($x, $y, $w, $h) {
        $this->page_content .= "{$x} {$y} {$w} {$h} re\n";
        $this->page_content .= "S\n";
    }
    
    private function escapeString($s) {
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace('(', '\\(', $s);
        $s = str_replace(')', '\\)', $s);
        return $s;
    }
    
    private function safe($t) {
        return @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $t) ?: $t;
    }
    
    private function buildPDF() {
        $this->buffer = '';
        $this->n = 0;
        $this->offsets = array();
        
        // Header
        $this->buffer .= "%PDF-1.4\n";
        $this->buffer .= "%\xE2\xE3\xCF\xD3\n";
        
        // Catalog
        $this->newObj();
        $this->buffer .= "<< /Type /Catalog /Pages 2 0 R >>\n";
        $this->buffer .= "endobj\n";
        
        // Pages
        $this->newObj();
        $this->buffer .= "<< /Type /Pages /Kids [3 0 R] /Count 1 >>\n";
        $this->buffer .= "endobj\n";
        
        // Page
        $this->newObj();
        $this->buffer .= "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] ";
        $this->buffer .= "/Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>\n";
        $this->buffer .= "endobj\n";
        
        // Content stream
        $this->newObj();
        $stream = $this->page_content;
        $this->buffer .= "<< /Length " . strlen($stream) . " >>\n";
        $this->buffer .= "stream\n";
        $this->buffer .= $stream;
        $this->buffer .= "endstream\n";
        $this->buffer .= "endobj\n";
        
        // Font Helvetica
        $this->newObj();
        $this->buffer .= "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\n";
        $this->buffer .= "endobj\n";
        
        // Font Helvetica-Bold
        $this->newObj();
        $this->buffer .= "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\n";
        $this->buffer .= "endobj\n";
        
        // xref
        $xref_offset = strlen($this->buffer);
        $this->buffer .= "xref\n";
        $this->buffer .= "0 " . ($this->n + 1) . "\n";
        $this->buffer .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $this->n; $i++) {
            $this->buffer .= sprintf("%010d 00000 n \n", $this->offsets[$i]);
        }
        
        // trailer
        $this->buffer .= "trailer\n";
        $this->buffer .= "<< /Size " . ($this->n + 1) . " /Root 1 0 R >>\n";
        $this->buffer .= "startxref\n";
        $this->buffer .= $xref_offset . "\n";
        $this->buffer .= "%%EOF";
        
        return $this->buffer;
    }
    
    private function newObj() {
        $this->n++;
        $this->offsets[$this->n] = strlen($this->buffer);
        $this->buffer .= $this->n . " 0 obj\n";
    }
}
