<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

/**
 * -------------------------------------------------------------------------
 * CONFIGURACIÓN
 * -------------------------------------------------------------------------
 */
$dbHost = 'localhost';
$dbName = 'glpi_externos_pre';
$dbUser = 'root';
$dbPass = '';

/**
 * ID del estado "Desafectado".
 */
$nuevoEstadoId = 3;

/**
 * id_search_option usado en glpi_logs para el campo "Estado".
 */
$idSearchOptionEstado = 31;

/**
 * linked_action usado en glpi_logs para enlazar con ticket.
 */
$linkedActionTicket = 15;

/**
 * -------------------------------------------------------------------------
 * FUNCIONES AUXILIARES
 * -------------------------------------------------------------------------
 */

/**
 * Escapa texto para salida HTML.
 */
function h(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

/**
 * Normaliza una cabecera del CSV.
 */
function normalizarCabecera(string $valor): string
{
    $valor = preg_replace('/^\xEF\xBB\xBF/', '', $valor);

    return strtolower(trim($valor));
}

/**
 * Devuelve la tabla GLPI asociada a un itemtype.
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

    return $mapa[$itemType] ?? null;
}

/**
 * Detecta automáticamente el delimitador del CSV.
 */
function detectarDelimitador(string $rutaCsv): string
{
    $handle = fopen($rutaCsv, 'r');

    if ($handle === false) {
        return ';';
    }

    $primeraLinea = fgets($handle);
    fclose($handle);

    if ($primeraLinea === false) {
        return ';';
    }

    $cantidadPuntoYComa = substr_count($primeraLinea, ';');
    $cantidadComas = substr_count($primeraLinea, ',');
    $cantidadTabs = substr_count($primeraLinea, "\t");

    if ($cantidadTabs > $cantidadPuntoYComa && $cantidadTabs > $cantidadComas) {
        return "\t";
    }

    if ($cantidadComas > $cantidadPuntoYComa) {
        return ',';
    }

    return ';';
}

/**
 * Obtiene el nombre visible del usuario para guardarlo en glpi_logs.
 */
function obtenerNombreUsuario(PDO $pdo, int $idUsuario): string
{
    $sql = "
        SELECT CONCAT(
            CASE
                WHEN TRIM(COALESCE(realname, '')) = '' AND TRIM(COALESCE(firstname, '')) = ''
                    THEN TRIM(COALESCE(name, ''))
                ELSE TRIM(CONCAT(COALESCE(realname, ''), ' ', COALESCE(firstname, '')))
            END,
            ' ({$idUsuario})'
        ) AS nombre
        FROM glpi_users
        WHERE id = :id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $idUsuario]);

    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    return $fila['nombre'] ?? "Usuario ({$idUsuario})";
}

/**
 * Obtiene el nombre de un estado GLPI a partir de su ID.
 */
function obtenerNombreEstado(PDO $pdo, int $estadoId): string
{
    $stmt = $pdo->prepare("
        SELECT name
        FROM glpi_states
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $estadoId]);

    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    return $fila['name'] ?? 'Desconocido';
}

/**
 * Obtiene el texto visible del ticket para guardarlo en glpi_logs.
 *
 * Formato:
 *   Título ticket (ID)
 */
function obtenerNombreTicket(PDO $pdo, int $ticketId): string
{
    $stmt = $pdo->prepare("
        SELECT CONCAT(COALESCE(name, ''), ' ({$ticketId})') AS nombre
        FROM glpi_tickets
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $ticketId]);

    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    return $fila['nombre'] ?? "Ticket ({$ticketId})";
}

/**
 * Obtiene el texto visible del dispositivo para guardarlo en glpi_logs.
 *
 * Formato:
 *   Nombre dispositivo (ID)
 */
function obtenerNombreDispositivo(PDO $pdo, string $tabla, int $itemsId): string
{
    $sql = "
        SELECT CONCAT(COALESCE(name, ''), ' ({$itemsId})') AS nombre
        FROM {$tabla}
        WHERE id = :id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $itemsId]);

    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    return $fila['nombre'] ?? "Elemento ({$itemsId})";
}

/**
 * Lee la primera línea del CSV y devuelve sus cabeceras.
 */
function leerCabecerasCsv(string $rutaCsv, string $delimitador): array
{
    $handle = fopen($rutaCsv, 'r');

    if ($handle === false) {
        throw new RuntimeException('No se pudo abrir el CSV.');
    }

    $cabeceras = fgetcsv($handle, 0, $delimitador);
    fclose($handle);

    if ($cabeceras === false) {
        throw new RuntimeException('No se pudieron leer las cabeceras del CSV.');
    }

    return $cabeceras;
}

/**
 * Comprueba si existe el ticket.
 */
function existeTicket(PDO $pdo, int $ticketId): bool
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM glpi_tickets
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $ticketId]);

    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Comprueba si el vínculo dispositivo-ticket ya existe.
 */
function existeVinculoTicket(PDO $pdo, string $itemType, int $itemsId, int $ticketId): bool
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM glpi_items_tickets
        WHERE itemtype = :itemtype
          AND items_id = :items_id
          AND tickets_id = :tickets_id
        LIMIT 1
    ");
    $stmt->execute([
        ':itemtype' => $itemType,
        ':items_id' => $itemsId,
        ':tickets_id' => $ticketId,
    ]);

    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Inserta un log de cambio de estado en glpi_logs.
 */
function insertarLogCambioEstado(
    PDO $pdo,
    string $itemType,
    int $itemsId,
    string $userName,
    int $idSearchOptionEstado,
    string $oldValue,
    string $newValue
): void {
    $sqlLog = "
        INSERT INTO glpi_logs
        (
            itemtype,
            items_id,
            itemtype_link,
            linked_action,
            user_name,
            date_mod,
            id_search_option,
            old_value,
            new_value
        )
        VALUES
        (
            :itemtype,
            :items_id,
            '',
            0,
            :user_name,
            NOW(),
            :id_search_option,
            :old_value,
            :new_value
        )
    ";

    $stmtLog = $pdo->prepare($sqlLog);
    $stmtLog->execute([
        ':itemtype' => $itemType,
        ':items_id' => $itemsId,
        ':user_name' => $userName,
        ':id_search_option' => $idSearchOptionEstado,
        ':old_value' => $oldValue,
        ':new_value' => $newValue,
    ]);
}

/**
 * Inserta el vínculo dispositivo-ticket y los dos logs de histórico:
 * - uno en el dispositivo
 * - otro en el ticket
 */
function insertarVinculoTicketYLogs(
    PDO $pdo,
    string $itemType,
    string $tabla,
    int $itemsId,
    int $ticketId,
    string $userName,
    int $linkedActionTicket
): void {
    $stmtRelacion = $pdo->prepare("
        INSERT INTO glpi_items_tickets
        (
            itemtype,
            items_id,
            tickets_id
        )
        VALUES
        (
            :itemtype,
            :items_id,
            :tickets_id
        )
    ");

    $stmtRelacion->execute([
        ':itemtype' => $itemType,
        ':items_id' => $itemsId,
        ':tickets_id' => $ticketId,
    ]);

    $nombreTicket = obtenerNombreTicket($pdo, $ticketId);
    $nombreDispositivo = obtenerNombreDispositivo($pdo, $tabla, $itemsId);

    // Log en el dispositivo
    $stmtLogDispositivo = $pdo->prepare("
        INSERT INTO glpi_logs
        (
            itemtype,
            items_id,
            itemtype_link,
            linked_action,
            user_name,
            date_mod,
            id_search_option,
            old_value,
            new_value
        )
        VALUES
        (
            :itemtype,
            :items_id,
            'Ticket',
            :linked_action,
            :user_name,
            NOW(),
            0,
            '',
            :new_value
        )
    ");

    $stmtLogDispositivo->execute([
        ':itemtype' => $itemType,
        ':items_id' => $itemsId,
        ':linked_action' => $linkedActionTicket,
        ':user_name' => $userName,
        ':new_value' => $nombreTicket,
    ]);

    // Log en el ticket
    $stmtLogTicket = $pdo->prepare("
        INSERT INTO glpi_logs
        (
            itemtype,
            items_id,
            itemtype_link,
            linked_action,
            user_name,
            date_mod,
            id_search_option,
            old_value,
            new_value
        )
        VALUES
        (
            'Ticket',
            :ticket_id,
            :itemtype_link,
            :linked_action,
            :user_name,
            NOW(),
            0,
            '',
            :new_value
        )
    ");

    $stmtLogTicket->execute([
        ':ticket_id' => $ticketId,
        ':itemtype_link' => $itemType,
        ':linked_action' => $linkedActionTicket,
        ':user_name' => $userName,
        ':new_value' => $nombreDispositivo,
    ]);
}

/**
 * -------------------------------------------------------------------------
 * CONEXIÓN A BASE DE DATOS
 * -------------------------------------------------------------------------
 */
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    die(
        '<h2>Error de conexión</h2><pre>' .
        h($e->getMessage()) .
        '</pre>'
    );
}

/**
 * -------------------------------------------------------------------------
 * VALIDACIÓN DE ENTRADA
 * -------------------------------------------------------------------------
 */
$file = $_GET['file'] ?? '';

if ($file === '') {
    die('<h2>Falta el parámetro file</h2><p>Ejemplo: <code>?file=PruebaDesafectacion</code></p>');
}

$rutaCsv = __DIR__ . '/' . basename($file) . '.csv';

if (!file_exists($rutaCsv)) {
    die('<h2>No se encontró el CSV</h2><pre>' . h($rutaCsv) . '</pre>');
}

$delimitador = detectarDelimitador($rutaCsv);

/**
 * -------------------------------------------------------------------------
 * PROCESO PRINCIPAL
 * -------------------------------------------------------------------------
 */
$resultados = [];
$totalFilas = 0;
$totalOk = 0;
$totalError = 0;
$totalYaEstaban = 0;

try {
    $cabeceras = leerCabecerasCsv($rutaCsv, $delimitador);
    $cabecerasNormalizadas = array_map('normalizarCabecera', $cabeceras);
    $indices = array_flip($cabecerasNormalizadas);

    if (!isset($indices['id_dispositivo'], $indices['itemtype'], $indices['id_user'], $indices['ticket_id'])) {
        throw new RuntimeException(
            'El CSV debe contener las columnas: id_dispositivo;itemtype;id_user;ticket_id'
        );
    }

    $handle = fopen($rutaCsv, 'r');

    if ($handle === false) {
        throw new RuntimeException('No se pudo abrir el CSV para procesarlo.');
    }

    fgetcsv($handle, 0, $delimitador);

    while (($fila = fgetcsv($handle, 0, $delimitador)) !== false) {
        if (count(array_filter($fila, fn($valor) => trim((string) $valor) !== '')) === 0) {
            continue;
        }

        $totalFilas++;

        $itemsId = (int) ($fila[$indices['id_dispositivo']] ?? 0);
        $itemType = trim((string) ($fila[$indices['itemtype']] ?? ''));
        $idUsuario = (int) ($fila[$indices['id_user']] ?? 0);
        $ticketId = (int) ($fila[$indices['ticket_id']] ?? 0);

        $resultado = [
            'fila' => $totalFilas,
            'items_id' => $itemsId,
            'itemtype' => $itemType,
            'tabla' => '',
            'usuario' => $idUsuario,
            'ticket_id' => $ticketId,
            'estado_anterior' => '',
            'estado_nuevo' => '',
            'resultado' => '',
        ];

        if ($itemsId <= 0 || $itemType === '' || $idUsuario <= 0 || $ticketId <= 0) {
            $resultado['resultado'] = 'ERROR';
            $resultados[] = $resultado;
            $totalError++;
            continue;
        }

        $tabla = obtenerTablaItem($itemType);

        if ($tabla === null) {
            $resultado['resultado'] = 'ERROR';
            $resultados[] = $resultado;
            $totalError++;
            continue;
        }

        $resultado['tabla'] = $tabla;

        try {
            $sqlCheck = "SELECT id, states_id FROM {$tabla} WHERE id = :id LIMIT 1";
            $stmtCheck = $pdo->prepare($sqlCheck);
            $stmtCheck->execute([':id' => $itemsId]);

            $registro = $stmtCheck->fetch();

            if (!$registro) {
                throw new RuntimeException(
                    "No existe el registro con id {$itemsId} en {$tabla}."
                );
            }

            if (!existeTicket($pdo, $ticketId)) {
                throw new RuntimeException(
                    "No existe el ticket con id {$ticketId}."
                );
            }

            $oldStateId = (int) ($registro['states_id'] ?? 0);
            $oldStateName = obtenerNombreEstado($pdo, $oldStateId);
            $newStateName = obtenerNombreEstado($pdo, $nuevoEstadoId);

            $oldValue = $oldStateName . " ({$oldStateId})";
            $newValue = $newStateName . " ({$nuevoEstadoId})";

            $resultado['estado_anterior'] = $oldValue;
            $resultado['estado_nuevo'] = $newValue;

            $yaEstabaDesafectado = ($oldStateId === $nuevoEstadoId);
            $userName = obtenerNombreUsuario($pdo, $idUsuario);

            $pdo->beginTransaction();

            if (!$yaEstabaDesafectado) {
                $stmtUpdate = $pdo->prepare(
                    "UPDATE {$tabla} SET states_id = :states_id WHERE id = :id"
                );
                $stmtUpdate->execute([
                    ':states_id' => $nuevoEstadoId,
                    ':id' => $itemsId,
                ]);

                insertarLogCambioEstado(
                    $pdo,
                    $itemType,
                    $itemsId,
                    $userName,
                    $idSearchOptionEstado,
                    $oldValue,
                    $newValue
                );
            }

            if (!existeVinculoTicket($pdo, $itemType, $itemsId, $ticketId)) {
                insertarVinculoTicketYLogs(
                    $pdo,
                    $itemType,
                    $tabla,
                    $itemsId,
                    $ticketId,
                    $userName,
                    $linkedActionTicket
                );
            }

            $pdo->commit();

            if ($yaEstabaDesafectado) {
                $resultado['resultado'] = 'YA ESTABA DESAFECTADO';
                $totalYaEstaban++;
            } else {
                $resultado['resultado'] = 'OK';
                $totalOk++;
            }

            $resultados[] = $resultado;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $resultado['resultado'] = 'ERROR';
            $resultados[] = $resultado;
            $totalError++;
        }
    }

    fclose($handle);
} catch (Throwable $e) {
    die('<h2>Error en el proceso</h2><pre>' . h($e->getMessage()) . '</pre>');
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resultado desafectación</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f7f7f7;
            color: #222;
        }

        h1, h2 {
            margin-bottom: 10px;
        }

        .bloque {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .resumen {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .tarjeta {
            background: #fafafa;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 12px 16px;
            min-width: 160px;
        }

        .ok {
            color: #0a7a2f;
            font-weight: bold;
        }

        .error {
            color: #b42318;
            font-weight: bold;
        }

        .ya-estaba {
            color: #005cc5;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 10px;
            vertical-align: top;
            text-align: left;
        }

        th {
            background: #f0f0f0;
        }
    </style>
</head>
<body>

    <h1>Resultado de la desafectación</h1>

    <div class="bloque">
        <h2>Resumen</h2>
        <div class="resumen">
            <div class="tarjeta">
                <strong>Total filas:</strong><br><?= (int) $totalFilas ?>
            </div>
            <div class="tarjeta">
                <span class="ok">Correctas:</span><br><?= (int) $totalOk ?>
            </div>
            <div class="tarjeta">
                <span class="ya-estaba">Ya estaban desafectados:</span><br><?= (int) $totalYaEstaban ?>
            </div>
            <div class="tarjeta">
                <span class="error">Errores:</span><br><?= (int) $totalError ?>
            </div>
        </div>
    </div>

    <div class="bloque">
        <h2>Detalle</h2>

        <table>
            <thead>
                <tr>
                    <th>Fila</th>
                    <th>ID dispositivo</th>
                    <th>Itemtype</th>
                    <th>Tabla</th>
                    <th>Usuario</th>
                    <th>Ticket</th>
                    <th>Estado anterior</th>
                    <th>Estado nuevo</th>
                    <th>Resultado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resultados as $resultadoFila): ?>
                    <tr>
                        <td><?= (int) $resultadoFila['fila'] ?></td>
                        <td><?= (int) $resultadoFila['items_id'] ?></td>
                        <td><?= h($resultadoFila['itemtype']) ?></td>
                        <td><?= h($resultadoFila['tabla']) ?></td>
                        <td><?= (int) $resultadoFila['usuario'] ?></td>
                        <td><?= (int) $resultadoFila['ticket_id'] ?></td>
                        <td><?= h($resultadoFila['estado_anterior']) ?></td>
                        <td><?= h($resultadoFila['estado_nuevo']) ?></td>
                        <td>
                            <?php if ($resultadoFila['resultado'] === 'OK'): ?>
                                <span class="ok"><?= h($resultadoFila['resultado']) ?></span>
                            <?php elseif ($resultadoFila['resultado'] === 'ERROR'): ?>
                                <span class="error"><?= h($resultadoFila['resultado']) ?></span>
                            <?php elseif ($resultadoFila['resultado'] === 'YA ESTABA DESAFECTADO'): ?>
                                <span class="ya-estaba"><?= h($resultadoFila['resultado']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</body>
</html>