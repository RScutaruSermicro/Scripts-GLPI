<?php

function normalizarCabecera(string $valor): string
{
    $valor = preg_replace('/^\xEF\xBB\xBF/', '', $valor);
    return strtolower(trim($valor));
}

/**
 * Obtiene el nombre de la tabla correspondiente a un tipo de elemento.
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
 * Devuelve SQL para construir el user_name del log.
 * Ejemplo: "Apellidos Nombre (100689)"
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

/**
 * Genera el SQL para sacar el estado anterior en formato:
 * "NombreEstado (id)"
 */
function generarSqlEstadoAnterior(string $tabla, int $itemsId): string
{
    return "(
        SELECT CONCAT(COALESCE(gs.name, ''), ' (', COALESCE(gd.states_id, 0), ')')
        FROM `{$tabla}` gd
        LEFT JOIN `glpi_states` gs ON gs.id = gd.states_id
        WHERE gd.id = {$itemsId}
    )";
}

/**
 * Devuelve el texto del nuevo estado para el log.
 */
function generarNuevoEstadoSql(int $nuevoEstadoId): string
{
    return "(
        SELECT CONCAT(COALESCE(name, ''), ' ({$nuevoEstadoId})')
        FROM `glpi_states`
        WHERE id = {$nuevoEstadoId}
    )";
}

function generarSqlUpdateEstado(string $tabla, int $itemsId, int $nuevoEstadoId): string
{
    return "UPDATE `{$tabla}` SET `states_id` = {$nuevoEstadoId} WHERE `id` = {$itemsId};";
}

function generarSqlInsertLogCambioEstado(
    string $itemType,
    int $itemsId,
    int $idUsuario,
    string $tabla,
    int $nuevoEstadoId
): string {
    $itemTypeSql = addslashes($itemType);
    $usuarioSql = generarSqlUsuario($idUsuario);
    $oldValueSql = generarSqlEstadoAnterior($tabla, $itemsId);
    $newValueSql = generarNuevoEstadoSql($nuevoEstadoId);

    return "INSERT INTO `glpi_logs`
(`id`, `itemtype`, `items_id`, `itemtype_link`, `linked_action`, `user_name`, `date_mod`, `id_search_option`, `old_value`, `new_value`)
VALUES
(NULL, '{$itemTypeSql}', {$itemsId}, '', 0, {$usuarioSql}, NOW(), 31, {$oldValueSql}, {$newValueSql});";
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

    if (!isset($indices['id_dispositivo'], $indices['itemtype'], $indices['id_user'])) {
        fclose($handle);
        echo '<pre>El CSV debe contener las cabeceras: id_dispositivo, itemtype e id_user.</pre>';
        return;
    }

    $nuevoEstadoId = 3;

    echo '<pre>';

    while (($fila = fgetcsv($handle, 0, $delimitador)) !== false) {
        $itemsId  = (int)($fila[$indices['id_dispositivo']] ?? 0);
        $itemType = trim($fila[$indices['itemtype']] ?? '');
        $idUsuario = (int)($fila[$indices['id_user']] ?? 0);

        if ($itemsId <= 0 || $itemType === '' || $idUsuario <= 0) {
            continue;
        }

        $tabla = obtenerTablaItem($itemType);

        if ($tabla === null) {
            echo "-- ItemType no reconocido: {$itemType}\n\n";
            continue;
        }

        // UPDATE del dispositivo
        echo generarSqlUpdateEstado($tabla, $itemsId, $nuevoEstadoId) . "\n";

        // INSERT en glpi_logs
        echo generarSqlInsertLogCambioEstado(
            $itemType,
            $itemsId,
            $idUsuario,
            $tabla,
            $nuevoEstadoId
        ) . "\n";

        echo "\n-- ------------------------------------------\n\n";
    }

    echo '</pre>';

    fclose($handle);
}

$file = ($_GET['file'] ?? '') . '.csv';
$rutaCsv = __DIR__ . '/' . $file;
generarInsertsDesdeCsv($rutaCsv);