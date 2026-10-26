<?php
// Lint de plantillas Twig: tokeniza y parsea todas las vistas registrando las
// mismas funciones/filtros/tests que ScreenBase, para detectar errores de sintaxis
// (ifs sin cerrar, funciones o filtros desconocidos) sin levantar la app.
// Uso: php tools/twig_lint.php
// Si añades una función/filtro en ScreenBase, añádelo también aquí.
require __DIR__ . '/../vendor/autoload.php';

$viewsdir = realpath(__DIR__ . '/../src/Screens/Views');
$loader = new \Twig\Loader\FilesystemLoader($viewsdir);
$twig = new \Twig\Environment($loader, ['cache' => false, 'strict_variables' => false]);
$twig->addExtension(new \Twig\Extension\DebugExtension());

foreach (['can', 'print_checkeliminar', 'debugbar_header', 'debugbar'] as $fn) {
  $twig->addFunction(new \Twig\TwigFunction($fn, function () { return ''; }));
}
foreach (['urlencodepreserveslash', 'json_decode', 'datetoint', 'datesqltofrontend', 'dateinttofrontend', 'datesqltoint', 'monedaaentero', 'monedaatexto', 'monedaatextous', 'truncate', 'filesizetotext'] as $f) {
  $twig->addFilter(new \Twig\TwigFilter($f, function ($v) { return $v; }));
}
$twig->addTest(new \Twig\TwigTest('array', function ($v) { return is_array($v); }));

$errors = 0;
$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($viewsdir));
foreach ($it as $file) {
  if (!$file->isFile() || $file->getExtension() !== 'html') continue;
  $name = str_replace('\\', '/', substr($file->getPathname(), strlen($viewsdir) + 1));
  try {
    $source = new \Twig\Source(file_get_contents($file->getPathname()), $name);
    $twig->parse($twig->tokenize($source));
  } catch (\Twig\Error\SyntaxError $e) {
    echo "ERROR  $name: " . $e->getMessage() . PHP_EOL;
    $errors++;
  }
}
echo $errors === 0 ? "OK - todas las plantillas parsean\n" : "$errors plantillas con error\n";
exit($errors === 0 ? 0 : 1);
