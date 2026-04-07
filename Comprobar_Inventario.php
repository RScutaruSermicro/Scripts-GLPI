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
 * -------------------------------------------------------------------------
 * DATOS DE FORMULARIO
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
$coincidencias = [];
$mensaje = '';
$error = '';

/**
 * -------------------------------------------------------------------------
 * PROCESO SOLO PARA TELÉFONOS
 * -------------------------------------------------------------------------
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $tipoSeleccionado === 'Teléfonos'
) {
    try {
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

        $cabeceras = fgetcsv($handle, 0, $delimitador);

        if ($cabeceras === false) {
            fclose($handle);
            throw new RuntimeException('No se pudieron leer las cabeceras del CSV.');
        }

        $indiceNumeroSerie = obtenerIndiceCabecera(
            $cabeceras,
            ['Número Serie', 'Numero Serie', 'Número Serial', 'Numero Serial']
        );

        $indiceCodigoInventario = obtenerIndiceCabecera(
            $cabeceras,
            ['Codigo Inventario', 'Código Inventario']
        );

        $indiceNombre = obtenerIndiceCabecera(
            $cabeceras,
            ['Nombre']
        );

        if ($indiceNumeroSerie === null) {
            fclose($handle);
            throw new RuntimeException(
                'No existe la columna "Número Serie" en el CSV.'
            );
        }

        $serialesCsv = [];
        $filasCsv = [];

        while (($fila = fgetcsv($handle, 0, $delimitador)) !== false) {
            if (count(array_filter($fila, fn($valor) => trim((string) $valor) !== '')) === 0) {
                continue;
            }

            $serialCsv = trim((string) ($fila[$indiceNumeroSerie] ?? ''));

            if ($serialCsv === '') {
                continue;
            }

            $serialesCsv[] = $serialCsv;
            $filasCsv[$serialCsv][] = [
                'codigo_inventario' => $indiceCodigoInventario !== null
                    ? trim((string) ($fila[$indiceCodigoInventario] ?? ''))
                    : '',
                'nombre_csv' => $indiceNombre !== null
                    ? trim((string) ($fila[$indiceNombre] ?? ''))
                    : '',
                'serial_csv' => $serialCsv,
            ];
        }

        fclose($handle);

        $serialesCsv = array_values(array_unique($serialesCsv));

        if ($serialesCsv === []) {
            throw new RuntimeException('El CSV no contiene valores en la columna "Número Serie".');
        }

        $pdo = new PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $placeholders = implode(',', array_fill(0, count($serialesCsv), '?'));

        $sql = "
            SELECT
                id,
                name,
                serial
            FROM glpi_phones
            WHERE serial IN ($placeholders)
            ORDER BY serial, id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($serialesCsv);

        $resultadosBd = $stmt->fetchAll();

        foreach ($resultadosBd as $filaBd) {
            $serialBd = trim((string) ($filaBd['serial'] ?? ''));

            if ($serialBd === '') {
                continue;
            }

            if (!isset($filasCsv[$serialBd])) {
                continue;
            }

            foreach ($filasCsv[$serialBd] as $filaCsv) {
                $coincidencias[] = [
                    'codigo_inventario_csv' => $filaCsv['codigo_inventario'],
                    'nombre_csv' => $filaCsv['nombre_csv'],
                    'serial_csv' => $filaCsv['serial_csv'],
                    'id_glpi' => (int) $filaBd['id'],
                    'nombre_glpi' => (string) ($filaBd['name'] ?? ''),
                    'serial_glpi' => $serialBd,
                ];
            }
        }

        if ($coincidencias === []) {
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
            max-width: 1100px;
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
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f0f0f0;
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

    <?php if ($coincidencias !== []): ?>
        <div class="bloque">
            <h2>Coincidencias encontradas</h2>

            <table>
                <thead>
                    <tr>
                        <th>Código inventario CSV</th>
                        <th>Nombre CSV</th>
                        <th>Número Serie CSV</th>
                        <th>ID GLPI</th>
                        <th>Nombre GLPI</th>
                        <th>Serial GLPI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($coincidencias as $coincidencia): ?>
                        <tr>
                            <td><?= h($coincidencia['codigo_inventario_csv']) ?></td>
                            <td><?= h($coincidencia['nombre_csv']) ?></td>
                            <td><?= h($coincidencia['serial_csv']) ?></td>
                            <td><?= (int) $coincidencia['id_glpi'] ?></td>
                            <td><?= h($coincidencia['nombre_glpi']) ?></td>
                            <td><?= h($coincidencia['serial_glpi']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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