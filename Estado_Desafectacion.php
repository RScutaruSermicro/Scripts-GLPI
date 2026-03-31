<?php

function normalizarCabecera(string $valor): string
{
    $valor = preg_replace('/^\xEF\xBB\xBF/', '', $valor);
    return strtolower(trim($valor));
}

/**
 * Obtiene el nombre de la tabla correspondiente a un tipo de elemento
 * 
 * @param string $itemType El tipo de elemento
 * @return string|null El nombre de la tabla o null si no se encuentra
 */
function obtenerTablaItem(string $itemType): ?string
{
    $mapa = [
        'Computer' => 'glpi_computers',
        'Monitor' => 'glpi_monitors',
        'Printer' => 'glpi_printers',
        'Peripheral' => 'glpi_peripherals',
        'Phone' => 'glpi_phones',
        'NetworkEquipment' => 'glpi_networkequipments',
        'Software' => 'glpi_softwares',
        'PluginGenericobjectPantalla' => 'glpi_plugin_genericobject_pantallas',
        'PluginGenericobjectProyector' => 'glpi_plugin_genericobject_proyectors',
        'PluginGenericobjectAudiovisual' => 'glpi_plugin_genericobject_audiovisuals',
        'PluginGenericobjectRobotica' => 'glpi_plugin_genericobject_roboticas',
        'PluginGenericobjectArmarioscarga' => 'glpi_plugin_genericobject_armarioscargas',
    ];

    if (isset($mapa[$itemType])) {
        return $mapa[$itemType];
    }

    if (!preg_match('/^[A-Za-z]+$/', $itemType)) {
        return null;
    }

    return 'glpi_' . strtolower($itemType) . 's';
}
/**
 * 
 * 
 */
function generarSqlUsuario(int $idUsuario): string
{
    if ($idUsuario <= 0) {
        return 'NULL';
    }

    return "(
SELECT CONCAT(
    CASE
        WHEN TRIM(COALESCE(realname, '')) = '' AND TRIM(COALESCE(firstname, '')) = ''
            THEN TRIM(COALESCE(name, ''))
        ELSE TRIM(CONCAT(COALESCE(realname, ''), ' ', COALESCE(firstname, '')))
    END,
    ' ({$idUsuario})'
)
FROM `glpi_users`
WHERE id = {$idUsuario}
)";
}


function generarSqlTicket(int $ticketsId): string
{
    return "(
SELECT CONCAT(COALESCE(name, ''), ' ({$ticketsId})')
FROM `glpi_tickets`
WHERE id = {$ticketsId}
)";
}

function generarSqlDispositivo(string $itemType, int $itemsId): string
{
    $tabla = obtenerTablaItem($itemType);

    if ($tabla === null) {
        return "'{$itemsId}'";
    }

    return "(
SELECT CONCAT(COALESCE(name, ''), ' ({$itemsId})')
FROM `{$tabla}`
WHERE id = {$itemsId}
)";
}

function generarInsertsDesdeCsv(string $rutaCsv): void
{
    if (!file_exists($rutaCsv)) {
        echo '<pre>No se encontro el fichero CSV: ' . htmlspecialchars($rutaCsv, ENT_QUOTES, 'UTF-8') . '</pre>';
        return;
    }

    $handle = fopen($rutaCsv, 'r');

    if ($handle === false) {
        echo '<pre>No se pudo abrir el fichero CSV.</pre>';
        return;
    }

    $primeraLinea = fgets($handle);

    if ($primeraLinea === false) {
        fclose($handle);
        echo '<pre>El fichero CSV esta vacio.</pre>';
        return;
    }

    $delimitador = ';';
    $cantidadPuntoYComa = substr_count($primeraLinea, ';');
    $cantidadComas = substr_count($primeraLinea, ',');
    $cantidadTabs = substr_count($primeraLinea, "\t");

    if ($cantidadTabs > $cantidadPuntoYComa && $cantidadTabs > $cantidadComas) {
        $delimitador = "\t";
    } elseif ($cantidadComas > $cantidadPuntoYComa) {
        $delimitador = ',';
    }

    rewind($handle);
    $cabeceras = fgetcsv($handle, 0, $delimitador);

    if ($cabeceras === false) {
        fclose($handle);
        echo '<pre>No se pudieron leer las cabeceras del CSV.</pre>';
        return;
    }

    $cabecerasNormalizadas = array_map('normalizarCabecera', $cabeceras);
    $indices = array_flip($cabecerasNormalizadas);

    if (!isset($indices['id_dispositivo'], $indices['ticket_id'], $indices['itemtype'], $indices['id_user'])) {
        fclose($handle);
        echo '<pre>El CSV debe contener las cabeceras: id_dispositivo, ticket_id, itemtype y id_user.</pre>';
        return;
    }

    $tieneIdUser = isset($indices['id_user']);

    echo '<pre>';

    while (($fila = fgetcsv($handle, 0, $delimitador)) !== false) {
        if(empty($fila[$indices['id_user']])){
            echo "NO TIENE USUARIO";
            return;
        }
        $itemsId = (int) ($fila[$indices['id_dispositivo']] ?? 0);
        $ticketsId = (int) ($fila[$indices['ticket_id']] ?? 0);
        $itemType = trim($fila[$indices['itemtype']] ?? '');
        // $idUsuario = $tieneIdUser ? (int) ($fila[$indices['id_user']] ?? 0) : 0;
        $idUsuario = $tieneIdUser ? (int) ($fila[$indices['id_user']] ?? 0) : 0;

        if ($itemsId <= 0 || $ticketsId <= 0 || $itemType === '') {
            continue;
        }

        $itemTypeSql = addslashes($itemType);
        $usuarioSql = generarSqlUsuario($idUsuario);
        $ticketSql = generarSqlTicket($ticketsId);
        $dispositivoSql = generarSqlDispositivo($itemType, $itemsId);

        // echo "INSERT INTO `glpi_items_tickets`(`id`, `itemtype`, `items_id`, `tickets_id`) ";
        // echo "VALUES (NULL, '{$itemTypeSql}', {$itemsId}, {$ticketsId});\n";

        // UPDATE del dispositivo
        //echo ""

        echo "INSERT INTO `glpi_logs`(`id`, `itemtype`, `items_id`, `itemtype_link`, `linked_action`, `user_name`, `date_mod`, `id_search_option`, `old_value`,`new_value`) ";
        echo "VALUES (NULL, '{$itemTypeSql}', {$itemsId}, 'Ticket', 15, {$usuarioSql}, NOW(), 0,'',{$ticketSql});\n";

        echo "<br>";
        echo "-- --<br>";
        echo "<br>";

    }

    echo '</pre>';

    fclose($handle);
}

// $file = $_GET['file'] ?? 'data.csv';
$file = $_GET['file'] . '.csv';
$rutaCsv = __DIR__ . '/' . $file;
generarInsertsDesdeCsv($rutaCsv);

