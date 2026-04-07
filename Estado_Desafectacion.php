<?php

declare(strict_types=1);

/**
 * CONFIGURACION BD
 */
$DB_HOST = 'localhost';
$DB_NAME = 'glpi_externos_pre';
$DB_USER = 'root';
$DB_PASS = ''; // pon aqui tu password si tienes
$NUEVO_ESTADO_ID = 3;

function normalizarCabecera(string $valor): string
{
    $valor = preg_replace('/^\xEF\xBB\xBF/', '', $valor);
    return strtolower(trim($valor));
}

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

function obtenerNombreUsuario(PDO $pdo, int $idUsuario): ?string
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
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    return $resultado['nombre'] ?? null;
}

function obtenerEstadoActual(PDO $pdo, string $tabla, int $itemsId): array
{
    $sql = "
        SELECT t.states_id, s.name AS estado_nombre
        FROM {$tabla} t
        LEFT JOIN glpi_states s ON s.id = t.states_id
        WHERE t.id = :id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $itemsId]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resultado) {
        return [
            'states_id' => null,
            'estado_nombre' => null
        ];
    }

    return $resultado;
}

function obtenerNombreEstado(PDO $pdo, int $estadoId): ?string
{
    $stmt = $pdo->prepare("SELECT name FROM glpi_states WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $estadoId]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    return $resultado['name'] ?? null;
}

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

function procesarCsv(PDO $pdo, string $rutaCsv, int $nuevoEstadoId): void
{
    if (!file_exists($rutaCsv)) {
        echo '<pre>No se encontro el fichero CSV: ' . htmlspecialchars($rutaCsv, ENT_QUOTES, 'UTF-8') . '</pre>';
        return;
    }

    $delimitador = detectarDelimitador($rutaCsv);
    $handle = fopen($rutaCsv, 'r');

    if ($handle === false) {
        echo '<pre>No se pudo abrir el CSV.</pre>';
        return;
    }

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
        echo '<pre>El CSV debe contener: id_dispositivo;itemtype;id_user</pre>';
        return;
    }

    $nombreNuevoEstado = obtenerNombreEstado($pdo, $nuevoEstadoId);
    $newValue = ($nombreNuevoEstado ?? 'Desconocido') . " ({$nuevoEstadoId})";

    echo '<pre>';
    echo "Inicio del proceso...\n\n";

    while (($fila = fgetcsv($handle, 0, $delimitador)) !== false) {
        $itemsId = (int)($fila[$indices['id_dispositivo']] ?? 0);
        $itemType = trim($fila[$indices['itemtype']] ?? '');
        $idUsuario = (int)($fila[$indices['id_user']] ?? 0);

        if ($itemsId <= 0 || $itemType === '' || $idUsuario <= 0) {
            echo "Fila ignorada por datos incompletos.\n";
            echo "----------------------------------------\n";
            continue;
        }

        $tabla = obtenerTablaItem($itemType);

        if ($tabla === null) {
            echo "ItemType no soportado: {$itemType}\n";
            echo "----------------------------------------\n";
            continue;
        }

        try {
            $pdo->beginTransaction();

            $estadoActual = obtenerEstadoActual($pdo, $tabla, $itemsId);

            if ($estadoActual['states_id'] === null) {
                throw new Exception("No existe el registro con id {$itemsId} en {$tabla}");
            }

            $oldStateId = (int)$estadoActual['states_id'];
            $oldStateName = $estadoActual['estado_nombre'] ?? 'Desconocido';
            $oldValue = $oldStateName . " ({$oldStateId})";

            if ($oldStateId === $nuevoEstadoId) {
                $pdo->rollBack();
                echo "ID {$itemsId} ({$itemType}): ya estaba en estado {$newValue}. Se omite.\n";
                echo "----------------------------------------\n";
                continue;
            }

            $sqlUpdate = "UPDATE {$tabla} SET states_id = :states_id WHERE id = :id";
            $stmtUpdate = $pdo->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':states_id' => $nuevoEstadoId,
                ':id' => $itemsId
            ]);

            if ($stmtUpdate->rowCount() === 0) {
                throw new Exception("No se pudo actualizar el id {$itemsId} en {$tabla}");
            }

            $userName = obtenerNombreUsuario($pdo, $idUsuario);
            if ($userName === null) {
                $userName = "Usuario ({$idUsuario})";
            }

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
                    31,
                    :old_value,
                    :new_value
                )
            ";

            $stmtLog = $pdo->prepare($sqlLog);
            $stmtLog->execute([
                ':itemtype' => $itemType,
                ':items_id' => $itemsId,
                ':user_name' => $userName,
                ':old_value' => $oldValue,
                ':new_value' => $newValue
            ]);

            $pdo->commit();

            echo "OK - ID {$itemsId} ({$itemType}) actualizado en {$tabla}\n";
            echo "     Estado anterior: {$oldValue}\n";
            echo "     Estado nuevo:    {$newValue}\n";
            echo "     Log insertado correctamente\n";
            echo "----------------------------------------\n";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            echo "ERROR - ID {$itemsId} ({$itemType}): " . $e->getMessage() . "\n";
            echo "----------------------------------------\n";
        }
    }

    fclose($handle);
    echo "\nProceso finalizado.\n";
    echo '</pre>';
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('<pre>Error de conexion a la base de datos: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>');
}

$file = $_GET['file'] ?? '';
if ($file === '') {
    die('<pre>Debes indicar el fichero en la URL. Ejemplo: ?file=PruebaDesafectacion</pre>');
}

$rutaCsv = __DIR__ . '/' . basename($file) . '.csv';
procesarCsv($pdo, $rutaCsv, $NUEVO_ESTADO_ID);