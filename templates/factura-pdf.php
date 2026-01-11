<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Factura <?php echo esc_html($letra . ' ' . $numero); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 12px; padding: 20px; color: #333; }
        
        .factura-container { max-width: 800px; margin: 0 auto; }
        
        .header { 
            display: flex; 
            border: 2px solid #000; 
            margin-bottom: 20px; 
        }
        
        .header-left, .header-right { 
            flex: 1; 
            padding: 15px; 
        }
        
        .header-center { 
            width: 80px; 
            display: flex; 
            flex-direction: column;
            align-items: center; 
            justify-content: center;
            border-left: 2px solid #000;
            border-right: 2px solid #000;
            background: #f5f5f5;
        }
        
        .tipo-letra { 
            font-size: 36px; 
            font-weight: bold; 
            border: 2px solid #000;
            background: #fff;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .codigo-comprobante { 
            font-size: 10px; 
            margin-top: 5px; 
        }
        
        .titulo { 
            font-size: 16px; 
            font-weight: bold; 
            margin-bottom: 10px; 
        }
        
        .dato { margin: 4px 0; }
        .dato-label { font-weight: bold; }
        
        .seccion { 
            border: 1px solid #ccc; 
            padding: 15px; 
            margin-bottom: 15px; 
        }
        
        .seccion-titulo {
            font-weight: bold;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
        }
        
        table.detalle {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        table.detalle th {
            background: #f0f0f0;
            padding: 10px;
            text-align: left;
            border: 1px solid #ccc;
        }
        
        table.detalle td {
            padding: 8px 10px;
            border: 1px solid #ccc;
        }
        
        table.detalle td.numero {
            text-align: right;
        }
        
        .totales {
            text-align: right;
        }
        
        .totales .linea {
            display: flex;
            justify-content: flex-end;
            margin: 5px 0;
        }
        
        .totales .linea span {
            width: 150px;
        }
        
        .totales .total-final {
            font-size: 16px;
            font-weight: bold;
            border-top: 2px solid #000;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .cae-section {
            background: #f9f9f9;
            padding: 15px;
            border: 1px solid #ccc;
            margin-top: 20px;
        }
        
        .codigo-barras {
            font-family: monospace;
            font-size: 10px;
            margin-top: 10px;
            word-break: break-all;
            background: #fff;
            padding: 5px;
            border: 1px dashed #ccc;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="factura-container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <div class="titulo"><?php echo esc_html($this->emisor['razon_social']); ?></div>
                <div class="dato">
                    <span class="dato-label">CUIT:</span> 
                    <?php echo esc_html($this->emisor['cuit']); ?>
                </div>
                <div class="dato">
                    <?php echo esc_html($this->get_condicion_iva_texto($this->emisor['condicion_iva'])); ?>
                </div>
                <div class="dato">
                    <span class="dato-label">Domicilio:</span> 
                    <?php echo esc_html($this->emisor['domicilio']); ?>
                </div>
                <div class="dato">
                    <span class="dato-label">IIBB:</span> 
                    <?php echo esc_html($this->emisor['ingresos_brutos']); ?>
                </div>
                <div class="dato">
                    <span class="dato-label">Inicio Act.:</span> 
                    <?php echo esc_html($this->emisor['inicio_actividades']); ?>
                </div>
            </div>
            
            <div class="header-center">
                <div class="tipo-letra"><?php echo esc_html($letra); ?></div>
                <div class="codigo-comprobante">
                    Cód. <?php echo str_pad($this->factura->tipo_comprobante, 3, '0', STR_PAD_LEFT); ?>
                </div>
            </div>
            
            <div class="header-right">
                <div class="titulo">FACTURA</div>
                <div class="dato">
                    <span class="dato-label">Punto de Venta:</span> 
                    <?php echo str_pad($this->factura->punto_venta, 5, '0', STR_PAD_LEFT); ?>
                </div>
                <div class="dato">
                    <span class="dato-label">Comp. Nro:</span> 
                    <?php echo str_pad($this->factura->numero_comprobante, 8, '0', STR_PAD_LEFT); ?>
                </div>
                <div class="dato">
                    <span class="dato-label">Fecha:</span> 
                    <?php echo date('d/m/Y', strtotime($this->factura->fecha_emision)); ?>
                </div>
            </div>
        </div>
        
        <!-- Datos del cliente -->
        <div class="seccion">
            <div class="seccion-titulo">DATOS DEL CLIENTE</div>
            <div class="dato">
                <span class="dato-label">Razón Social:</span> 
                <?php echo esc_html($this->factura->receptor_nombre); ?>
            </div>
            <?php if ($this->factura->receptor_cuit): ?>
            <div class="dato">
                <span class="dato-label">CUIT:</span> 
                <?php echo esc_html(WCAFIP_Validador_CUIT::formatear($this->factura->receptor_cuit)); ?>
            </div>
            <?php elseif ($this->factura->receptor_dni): ?>
            <div class="dato">
                <span class="dato-label">DNI:</span> 
                <?php echo esc_html($this->factura->receptor_dni); ?>
            </div>
            <?php endif; ?>
            <div class="dato">
                <span class="dato-label">Condición IVA:</span> 
                <?php echo esc_html($this->get_condicion_iva_codigo_texto($this->factura->receptor_condicion_iva)); ?>
            </div>
            <div class="dato">
                <span class="dato-label">Condición de Venta:</span> Contado
            </div>
        </div>
        
        <!-- Detalle -->
        <table class="detalle">
            <thead>
                <tr>
                    <th width="60">Cant.</th>
                    <th>Descripción</th>
                    <th width="100">P. Unit.</th>
                    <th width="100">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($this->order): ?>
                    <?php foreach ($this->order->get_items() as $item): ?>
                        <?php
                        $qty = $item->get_quantity();
                        $total = $item->get_total();
                        $unit_price = $qty > 0 ? $total / $qty : $total;
                        ?>
                        <tr>
                            <td class="numero"><?php echo esc_html($qty); ?></td>
                            <td><?php echo esc_html($item->get_name()); ?></td>
                            <td class="numero">$<?php echo number_format($unit_price, 2, ',', '.'); ?></td>
                            <td class="numero">$<?php echo number_format($total, 2, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td class="numero">1</td>
                        <td>Servicios varios</td>
                        <td class="numero">$<?php echo number_format($this->factura->importe_total, 2, ',', '.'); ?></td>
                        <td class="numero">$<?php echo number_format($this->factura->importe_total, 2, ',', '.'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Totales -->
        <div class="totales">
            <?php if ($this->factura->tipo_comprobante == WCAFIP_WSFE::FACTURA_A): ?>
            <div class="linea">
                <span>Subtotal:</span>
                <span>$<?php echo number_format($this->factura->importe_neto, 2, ',', '.'); ?></span>
            </div>
            <div class="linea">
                <span>IVA 21%:</span>
                <span>$<?php echo number_format($this->factura->importe_iva, 2, ',', '.'); ?></span>
            </div>
            <?php endif; ?>
            <div class="linea total-final">
                <span>TOTAL:</span>
                <span>$<?php echo number_format($this->factura->importe_total, 2, ',', '.'); ?></span>
            </div>
        </div>
        
        <!-- CAE -->
        <div class="cae-section">
            <div class="dato">
                <span class="dato-label">CAE:</span> 
                <?php echo esc_html($this->factura->cae); ?>
            </div>
            <div class="dato">
                <span class="dato-label">Fecha Vto. CAE:</span> 
                <?php echo date('d/m/Y', strtotime($this->factura->cae_fecha_vto)); ?>
            </div>
            <div class="codigo-barras">
                <?php echo esc_html($this->generar_codigo_barras()); ?>
            </div>
        </div>
        
        <div class="footer">
            Comprobante autorizado por AFIP - CAE (Código de Autorización Electrónico)
        </div>
    </div>
</body>
</html>
