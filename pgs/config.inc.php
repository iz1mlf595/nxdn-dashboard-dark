<?php
// ==============================================================================
// FILE DI CONFIGURAZIONE DASHBOARD NXDN REFLECTOR
// ==============================================================================

// Informazioni Generali
define('DASHBOARD_VERSION', '1.3 PRO Live');
define('ADMIN_EMAIL', 'nomail@nomail.it');

// Intestazione Dashboard
define('HEADER_SUBTITLE', 'NXDN REFLECTOR');
define('HEADER_TITLE', 'NXDN REFLECTOR');

// Badge Personalizzati
define('BADGE_TG', 'TG #123456');
define('BADGE_XLX', 'XLX999 Module Z');
define('BADGE_DMR', 'DMR TG123456@BM');
define('BADGE_YSF', 'YSF REFLECTOR #12345');

// Percorsi di Sistema (Log e File di Configurazione/INi)
define('LOG_DIR_PATH', '/var/log/NXDNReflector/');
define('REFLECTOR_INI_PATH', '/etc/NXDNReflector.ini'); // Esempio di percorso file ini

// Mascheramento IP Gateway
// Opzioni disponibili:
// 'full'       -> Mostra IP completo (es. 192.168.2.35)
// 'last_octet' -> Mostra solo l'ultimo numero (es. ***.***.***.35)
// 'last_two'   -> Mostra gli ultimi due numeri (es. ***.168.2.35)
define('IP_MASK_MODE', 'last_two');

/**
 * Funzione helper per formattare l'indirizzo IP in base alle impostazioni
 */
function format_gateway_ip($ip, $mode = IP_MASK_MODE) {
    $octets = explode('.', $ip);
    if (count($octets) !== 4) {
        return $ip;
    }
    
    switch ($mode) {
        case 'full':
            return $ip;
        case 'last_octet':
            return '***.***.***.' . $octets[3];
        case 'last_two':
            return '***.' . $octets[1] . '.' . $octets[2] . '.' . $octets[3];
        default:
            return '***.***.' . $octets[2] . '.' . $octets[3];
    }
}