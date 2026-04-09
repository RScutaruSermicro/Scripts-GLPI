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
 * Valor de número de serie que no debe dar error.
 */
$serialIgnorado = 'S/N';

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
 * Normaliza texto:
 * - quita BOM
 * - trim
 * - minúsculas
 * - sin tildes
 */
function normalizarTexto(string $texto): string
{
    $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
    $texto = trim($texto);
    $texto = mb_strtolower($texto, 'UTF-8');

    $reemplazos = [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ñ' => 'n',
    ];

    return strtr($texto, $reemplazos);
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
 * Devuelve el índice de una cabecera si existe.
 */
function obtenerIndiceCabecera(array $cabeceras, array $posiblesNombres): ?int
{
    $cabecerasNormalizadas = array_map(
        static fn(string $cabecera): string => normalizarTexto($cabecera),
        $cabeceras
    );

    foreach ($posiblesNombres as $nombre) {
        $nombreNormalizado = normalizarTexto($nombre);
        $indice = array_search($nombreNormalizado, $cabecerasNormalizadas, true);

        if ($indice !== false) {
            return (int) $indice;
        }
    }

    return null;
}

/**
 * Devuelve la configuración de comprobación según la categoría seleccionada.
 *
 * Se asume que en todas estas tablas el campo del número de serie es "serial".
 * Si alguna tabla usa otro nombre, cámbialo aquí.
 */
function obtenerConfiguracionInventario(string $tipoSeleccionado): ?array
{
    $mapa = [
        'AudioVisuales' => [
            'tabla' => 'glpi_plugin_genericobject_audiovisuals',
            'campo_serial' => 'serial',
        ],
        'Dispositivos de Red' => [
            'tabla' => 'glpi_networkequipments',
            'campo_serial' => 'serial',
        ],
        'Monitores' => [
            'tabla' => 'glpi_monitors',
            'campo_serial' => 'serial',
        ],
        'Ordenadores' => [
            'tabla' => 'glpi_computers',
            'campo_serial' => 'serial',
        ],
        'Pantallas' => [
            'tabla' => 'glpi_plugin_genericobject_pantallas',
            'campo_serial' => 'serial',
        ],
        'Periféricos' => [
            'tabla' => 'glpi_peripherals',
            'campo_serial' => 'serial',
        ],
        'Proyectores' => [
            'tabla' => 'glpi_plugin_genericobject_proyectors',
            'campo_serial' => 'serial',
        ],
        'Robóticas' => [
            'tabla' => 'glpi_plugin_genericobject_roboticas',
            'campo_serial' => 'serial',
        ],
        'Teléfonos' => [
            'tabla' => 'glpi_phones',
            'campo_serial' => 'serial',
        ],
    ];

    return $mapa[$tipoSeleccionado] ?? null;
}

/**
 * -------------------------------------------------------------------------
 * DATOS DEL FORMULARIO
 * -------------------------------------------------------------------------
 */
$opcionesInventario = [
    'AudioVisuales',
    'Dispositivos de Red',
    'Monitores',
    'Ordenadores',
    'Pantallas',
    'Periféricos',
    'Proyectores',
    'Robóticas',
    'Teléfonos',
];

$tipoSeleccionado = $_POST['tipo_inventario'] ?? '';
$error = '';
$mensaje = '';
$cabecerasCsv = [];
$filasTabla = [];

/**
 * -------------------------------------------------------------------------
 * PROCESO
 * -------------------------------------------------------------------------
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $tipoSeleccionado !== ''
) {
    try {
        $configuracion = obtenerConfiguracionInventario($tipoSeleccionado);

        if ($configuracion === null) {
            throw new RuntimeException(
                'La categoría seleccionada no tiene configuración de comprobación.'
            );
        }

        if (
            !isset($_FILES['archivo_csv'])
            || !is_array($_FILES['archivo_csv'])
            || ($_FILES['archivo_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            throw new RuntimeException('Debes adjuntar un archivo CSV.');
        }

        $rutaTemporal = $_FILES['archivo_csv']['tmp_name'];

        if ($rutaTemporal === '' || !is_uploaded_file($rutaTemporal)) {
            throw new RuntimeException('No se pudo procesar el archivo subido.');
        }

        $delimitador = detectarDelimitador($rutaTemporal);
        $handle = fopen($rutaTemporal, 'r');

        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el CSV.');
        }

        $cabecerasCsv = fgetcsv($handle, 0, $delimitador);

        if ($cabecerasCsv === false) {
            fclose($handle);
            throw new RuntimeException('No se pudieron leer las cabeceras del CSV.');
        }

        $indiceNumeroSerie = obtenerIndiceCabecera(
            $cabecerasCsv,
            ['Número Serie', 'Numero Serie', 'Número Serial', 'Numero Serial']
        );

        if ($indiceNumeroSerie === null) {
            fclose($handle);
            throw new RuntimeException('No existe la columna "Número Serie" en el CSV.');
        }

        $filasCsvCrudas = [];
        $serialesCsvNormalizados = [];

        while (($fila = fgetcsv($handle, 0, $delimitador)) !== false) {
            if (count(array_filter($fila, fn($valor) => trim((string) $valor) !== '')) === 0) {
                continue;
            }

            $serial = trim((string) ($fila[$indiceNumeroSerie] ?? ''));

            $filasCsvCrudas[] = $fila;

            if ($serial !== '' && $serial !== $serialIgnorado) {
                $serialesCsvNormalizados[] = normalizarTexto($serial);
            }
        }

        fclose($handle);

        if ($filasCsvCrudas === []) {
            throw new RuntimeException('El CSV no contiene filas de datos.');
        }

        $serialesCsvNormalizados = array_values(array_unique($serialesCsvNormalizados));
        $serialesExistentes = [];

        if ($serialesCsvNormalizados !== []) {
            $pdo = new PDO(
                "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            $tabla = $configuracion['tabla'];
            $campoSerial = $configuracion['campo_serial'];

            $sql = "
                SELECT {$campoSerial} AS serial
                FROM {$tabla}
                WHERE {$campoSerial} IS NOT NULL
                  AND {$campoSerial} <> ''
            ";

            $stmt = $pdo->query($sql);
            $resultadosBd = $stmt->fetchAll();

            foreach ($resultadosBd as $filaBd) {
                $serialBd = trim((string) ($filaBd['serial'] ?? ''));

                if ($serialBd !== '') {
                    $serialesExistentes[normalizarTexto($serialBd)] = true;
                }
            }
        }

        foreach ($filasCsvCrudas as $fila) {
            $observaciones = [];
            $celdasError = [];

            $serial = trim((string) ($fila[$indiceNumeroSerie] ?? ''));
            $serialNormalizado = normalizarTexto($serial);

            if (
                $serial !== ''
                && $serial !== $serialIgnorado
                && isset($serialesExistentes[$serialNormalizado])
            ) {
                $observaciones[] = 'El número de serie ya existe';
                $celdasError[] = $indiceNumeroSerie;
            }

            $filasTabla[] = [
                'datos' => $fila,
                'celdas_error' => $celdasError,
                'observaciones' => implode(' | ', $observaciones),
            ];
        }

        $hayErrores = false;

        foreach ($filasTabla as $filaTabla) {
            if ($filaTabla['observaciones'] !== '') {
                $hayErrores = true;
                break;
            }
        }

        if (!$hayErrores) {
            $mensaje = 'No hay coincidencias.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobar Inventario</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f7f7f7;
            color: #222;
        }

        .bloque {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            max-width: 1400px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }

        select,
        input[type="file"],
        button {
            width: 100%;
            padding: 10px;
            font-size: 14px;
            margin-bottom: 12px;
            box-sizing: border-box;
        }

        .oculto {
            display: none;
        }

        .mensaje-ok {
            color: #0a7a2f;
            font-weight: bold;
        }

        .mensaje-error {
            color: #b42318;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            font-size: 14px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f0f0f0;
            position: sticky;
            top: 0;
        }

        .celda-error {
            background: #fbd5d5;
            color: #8a1c1c;
            font-weight: bold;
        }

        .observacion-error {
            color: #b42318;
            font-weight: bold;
        }

        .tabla-scroll {
            overflow-x: auto;
        }
    </style>
</head>
<body>

    <div class="bloque">
        <h1>Comprobar inventario</h1>

        <form method="post" enctype="multipart/form-data">
            <label for="tipo_inventario">Selecciona una categoría</label>
            <select name="tipo_inventario" id="tipo_inventario">
                <option value="">-- Selecciona una opción --</option>
                <?php foreach ($opcionesInventario as $opcion): ?>
                    <option
                        value="<?= h($opcion) ?>"
                        <?= $tipoSeleccionado === $opcion ? 'selected' : '' ?>
                    >
                        <?= h($opcion) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div id="bloqueCsv" class="<?= $tipoSeleccionado !== '' ? '' : 'oculto' ?>">
                <label for="archivo_csv">Adjuntar CSV</label>
                <input
                    type="file"
                    name="archivo_csv"
                    id="archivo_csv"
                    accept=".csv,text/csv"
                >
            </div>

            <button type="submit">Comprobar</button>
        </form>
    </div>

    <?php if ($error !== ''): ?>
        <div class="bloque">
            <p class="mensaje-error"><?= h($error) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($mensaje !== ''): ?>
        <div class="bloque">
            <p class="mensaje-ok"><?= h($mensaje) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($filasTabla !== [] && $cabecerasCsv !== []): ?>
        <div class="bloque">
            <h2>Resultado de la comprobación</h2>

            <div class="tabla-scroll">
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($cabecerasCsv as $cabecera): ?>
                                <th><?= h((string) $cabecera) ?></th>
                            <?php endforeach; ?>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filasTabla as $filaTabla): ?>
                            <tr>
                                <?php foreach ($filaTabla['datos'] as $indice => $valor): ?>
                                    <td class="<?= in_array($indice, $filaTabla['celdas_error'], true) ? 'celda-error' : '' ?>">
                                        <?= h((string) $valor) ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="<?= $filaTabla['observaciones'] !== '' ? 'observacion-error' : '' ?>">
                                    <?= h($filaTabla['observaciones']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <script>
        const selectInventario = document.getElementById('tipo_inventario');
        const bloqueCsv = document.getElementById('bloqueCsv');

        selectInventario.addEventListener('change', function () {
            if (this.value !== '') {
                bloqueCsv.classList.remove('oculto');
            } else {
                bloqueCsv.classList.add('oculto');
            }
        });
    </script>

</body>
</html>