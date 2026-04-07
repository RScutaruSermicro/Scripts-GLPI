<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

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
            max-width: 500px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }

        select,
        button {
            padding: 8px 10px;
            font-size: 14px;
        }

        select {
            width: 100%;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>

    <div class="bloque">
        <h1>Comprobar inventario</h1>

        <form method="post">
            <label for="tipo_inventario">Selecciona una categoría</label>
            <select name="tipo_inventario" id="tipo_inventario">
                <option value="">-- Selecciona una opción --</option>
                <?php foreach ($opcionesInventario as $opcion): ?>
                    <option value="<?= htmlspecialchars($opcion, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $tipoSeleccionado === $opcion ? 'selected' : '' ?>>
                        <?= htmlspecialchars($opcion, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Comprobar</button>
        </form>

        <?php if ($tipoSeleccionado !== ''): ?>
            <p><strong>Has seleccionado:</strong> <?= htmlspecialchars($tipoSeleccionado, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>

</body>
</html>