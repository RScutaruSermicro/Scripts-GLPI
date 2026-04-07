<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| CONFIGURACION
|--------------------------------------------------------------------------
*/
$DB_HOST = 'localhost';
$DB_NAME = 'glpi_externos_pre';
$DB_USER = 'root';
$DB_PASS = '';
$NUEVO_ESTADO_ID = 3;

/*
|--------------------------------------------------------------------------
| FUNCIONES AUXILIARES
|--------------------------------------------------------------------------
*/
function h(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

function normalizarCabecera(string $valor): string
{
    $valor = preg_replace('/^\xEF\xBB\xBF/', '', $valor);
    return strtolower(trim($valor));
}

function obtenerTablaItem(string $itemType): ?string
{
    $mapa = [
        'Computer'                      => 'glpi_computers',
        'Monitor'                       => 'glpi_monitors',
        'Printer'                       => 'glpi_printers',
        'Peripheral'                    => 'glpi_peripherals',
        'Phone'                         => 'glpi_phones',
        'NetworkEquipment'              => 'glpi_networkequipments',
        'Software'                      => 'glpi_softwares',
        'PluginGenericobjectPantalla'   => 'glpi_plugin_genericobject_pantallas',
        'PluginGenericobjectProyector'  => 'glpi_plugin_genericobject_proyectors',
        'PluginGenericobjectAudiovisual'=> 'glpi_plugin_genericobject_audiovisuals',
        'PluginGenericobjectRobotica'   => 'glpi_plugin_genericobject_roboticas',
        'PluginGenericobjectArmarioscarga' => 'glpi_plugin_genericobject_armarioscargas',
    ];

    return $mapa[$itemType] ?? null;
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

/*
|--------------------------------------------------------------------------
| CONEXION BD
|--------------------------------------------------------------------------
*/
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
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

/*
|--------------------------------------------------------------------------
| VALIDACION ENTRADA
|--------------------------------------------------------------------------
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

/*
|--------------------------------------------------------------------------
| PROCESO
|--------------------------------------------------------------------------
*/
$resultados = [];
$totalFilas = 0;
$totalOk = 0;
$totalError = 0;
$totalOmitidas = 0;

try {
    $cabeceras = leerCabecerasCsv($rutaCsv, $delimitador);
    $cabecerasNormalizadas = array_map('normalizarCabecera', $cabeceras);
    $indices = array_flip($cabecerasNormalizadas);

    if (!isset($indices['id_dispositivo'], $indices['itemtype'], $indices['id_user'])) {
        throw new RuntimeException('El CSV debe contener las columnas: id_dispositivo;itemtype;id_user');
    }

    $handle = fopen($rutaCsv, 'r');

    if ($handle === false) {
        throw new RuntimeException('No se pudo abrir el CSV para procesarlo.');
    }

    fgetcsv($handle, 0, $delimitador); // Saltar cabecera

    while (($fila = fgetcsv($handle, 0, $delimitador)) !== false) {
        if (count(array_filter($fila, fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }

        $totalFilas++;

        $itemsId   = (int)($fila[$indices['id_dispositivo']] ?? 0);
        $itemType  = trim((string)($fila[$indices['itemtype']] ?? ''));
        $idUsuario = (int)($fila[$indices['id_user']] ?? 0);

        $resultado = [
            'fila'            => $totalFilas,
            'items_id'        => $itemsId,
            'itemtype'        => $itemType,
            'tabla'           => '',
            'usuario'         => $idUsuario,
            'estado_anterior' => '',
            'estado_nuevo'    => '',
            'resultado'       => '',
            'mensaje'         => '',
        ];

        if ($itemsId <= 0 || $itemType === '' || $idUsuario <= 0) {
            $resultado['resultado'] = 'OMITIDA';
            $resultado['mensaje'] = 'Datos incompletos o no válidos.';
            $resultados[] = $resultado;
            $totalOmitidas++;
            continue;
        }

        $tabla = obtenerTablaItem($itemType);

        if ($tabla === null) {
            $resultado['resultado'] = 'ERROR';
            $resultado['mensaje'] = "No hay tabla asociada para el itemtype '{$itemType}'.";
            $resultados[] = $resultado;
            $totalError++;
            continue;
        }

        $resultado['tabla'] = $tabla;

        try {
            $pdo->beginTransaction();

            $sqlCheck = "SELECT id, states_id FROM {$tabla} WHERE id = :id LIMIT 1";
            $stmtCheck = $pdo->prepare($sqlCheck);
            $stmtCheck->execute([':id' => $itemsId]);
            $registro = $stmtCheck->fetch();

            if (!$registro) {
                throw new RuntimeException("No existe el registro con id {$itemsId} en {$tabla}.");
            }

            $oldStateId = (int)($registro['states_id'] ?? 0);
            $oldStateName = obtenerNombreEstado($pdo, $oldStateId);
            $newStateName = obtenerNombreEstado($pdo, $NUEVO_ESTADO_ID);

            $oldValue = $oldStateName . " ({$oldStateId})";
            $newValue = $newStateName . " ({$NUEVO_ESTADO_ID})";

            $resultado['estado_anterior'] = $oldValue;
            $resultado['estado_nuevo'] = $newValue;

            $stmtUpdate = $pdo->prepare("UPDATE {$tabla} SET states_id = :states_id WHERE id = :id");
            $stmtUpdate->execute([
                ':states_id' => $NUEVO_ESTADO_ID,
                ':id'        => $itemsId,
            ]);

            $userName = obtenerNombreUsuario($pdo, $idUsuario);

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
                ':itemtype'  => $itemType,
                ':items_id'  => $itemsId,
                ':user_name' => $userName,
                ':old_value' => $oldValue,
                ':new_value' => $newValue,
            ]);

            $pdo->commit();

            $resultado['resultado'] = 'OK';
            $resultado['mensaje'] = 'Update e insert realizados correctamente.';
            $resultados[] = $resultado;
            $totalOk++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $resultado['resultado'] = 'ERROR';
            $resultado['mensaje'] = $e->getMessage();
            $resultados[] = $resultado;
            $totalError++;
        }
    }

    fclose($handle);
} catch (Throwable $e) {
    die('<h2>Error en el proceso</h2><pre>' . h($e->getMessage()) . '</pre>');
}

/*
|--------------------------------------------------------------------------
| SALIDA HTML
|--------------------------------------------------------------------------
*/
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

        .omitida {
            color: #9a6700;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            vertical-align: top;
            text-align: left;
        }

        th {
            background: #f0f0f0;
        }

        code {
            background: #f3f3f3;
            padding: 2px 6px;
            border-radius: 4px;
        }
    </style>
</head>
<body>

    <h1>Proceso de desafectación</h1>

    <div class="bloque">
        <h2>Información</h2>
        <p><strong>Base de datos:</strong> <?= h($DB_NAME) ?></p>
        <p><strong>CSV:</strong> <?= h($rutaCsv) ?></p>
        <p><strong>Delimitador detectado:</strong> <code><?= h($delimitador === "\t" ? '\t' : $delimitador) ?></code></p>
        <p><strong>Estado nuevo aplicado:</strong> <?= (int)$NUEVO_ESTADO_ID ?></p>
    </div>

    <div class="bloque">
        <h2>Resumen</h2>
        <div class="resumen">
            <div class="tarjeta"><strong>Total filas:</strong><br><?= (int)$totalFilas ?></div>
            <div class="tarjeta"><span class="ok">Correctas:</span><br><?= (int)$totalOk ?></div>
            <div class="tarjeta"><span class="error">Errores:</span><br><?= (int)$totalError ?></div>
            <div class="tarjeta"><span class="omitida">Omitidas:</span><br><?= (int)$totalOmitidas ?></div>
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
                    <th>Estado anterior</th>
                    <th>Estado nuevo</th>
                    <th>Resultado</th>
                    <th>Mensaje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resultados as $r): ?>
                    <tr>
                        <td><?= (int)$r['fila'] ?></td>
                        <td><?= (int)$r['items_id'] ?></td>
                        <td><?= h($r['itemtype']) ?></td>
                        <td><?= h($r['tabla']) ?></td>
                        <td><?= (int)$r['usuario'] ?></td>
                        <td><?= h($r['estado_anterior']) ?></td>
                        <td><?= h($r['estado_nuevo']) ?></td>
                        <td>
                            <?php if ($r['resultado'] === 'OK'): ?>
                                <span class="ok"><?= h($r['resultado']) ?></span>
                            <?php elseif ($r['resultado'] === 'ERROR'): ?>
                                <span class="error"><?= h($r['resultado']) ?></span>
                            <?php else: ?>
                                <span class="omitida"><?= h($r['resultado']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($r['mensaje']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</body>
</html>