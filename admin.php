<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, private');

$authorsFile = __DIR__ . DIRECTORY_SEPARATOR . 'autores.json';
$authorsDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'autores_uploads';
$authorsPublicPath = 'autores_uploads/';
$artistsFile = __DIR__ . DIRECTORY_SEPARATOR . 'artists.json';
$artistsDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'imgs' . DIRECTORY_SEPARATOR . 'artists';
$adminUsername = 'admin';
$adminPasswordHash = '$2y$10$llJ5us3IrRX7z90aaUYw..oXbDaW3TbV5Q/u0J.XB406LghXi5x/G';
$artistMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/avif' => 'avif',
];
$maxAuthorUploadBytes = 5 * 1024 * 1024;
$maxArtistUploadBytes = 8 * 1024 * 1024;

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function loadJsonList(string $file, string $label): array
{
    if (!is_file($file)) {
        return [];
    }

    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException('Não foi possível ler ' . $label . '. Verifique as permissões do arquivo.');
    }

    $items = json_decode($contents, true);
    if (!is_array($items) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException($label . ' contém dados inválidos. Corrija o arquivo antes de continuar.');
    }

    return $items;
}

function saveJsonList(string $file, array $items, string $label): void
{
    $json = json_encode(
        array_values($items),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($json === false) {
        throw new RuntimeException('Não foi possível converter os dados de ' . $label . ' para JSON.');
    }

    $temporaryFile = tempnam(dirname($file), '.content-');
    if ($temporaryFile === false) {
        throw new RuntimeException('Não foi possível criar um arquivo temporário para salvar ' . $label . '.');
    }

    try {
        if (file_put_contents($temporaryFile, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar ' . $label . '. Verifique as permissões do arquivo.');
        }
        if (!rename($temporaryFile, $file)) {
            throw new RuntimeException('Não foi possível atualizar ' . $label . '. Verifique as permissões da pasta.');
        }
    } finally {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }
}

function validateText($value, string $label, int $maxLength, bool $required): string
{
    if (!is_string($value)) {
        throw new RuntimeException('O campo ' . $label . ' é inválido.');
    }

    $value = trim($value);
    if ($required && $value === '') {
        throw new RuntimeException('Preencha o campo ' . $label . '.');
    }
    if (strlen($value) > $maxLength || preg_match('//u', $value) !== 1) {
        throw new RuntimeException('O campo ' . $label . ' deve ter até ' . $maxLength . ' bytes e conter texto válido.');
    }

    return $value;
}

function storeUploadedPhoto(array $upload, string $directory, int $maxBytes, array $allowedTypes): string
{
    if (!isset($upload['error']) || is_array($upload['error'])) {
        throw new RuntimeException('O envio da foto não foi reconhecido.');
    }
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'A foto excede o limite definido pelo servidor.',
            UPLOAD_ERR_FORM_SIZE => 'A foto excede o limite permitido.',
            UPLOAD_ERR_PARTIAL => 'A foto foi enviada parcialmente. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Selecione uma foto.',
            UPLOAD_ERR_NO_TMP_DIR => 'O servidor não possui uma pasta temporária para uploads.',
            UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar a foto.',
            UPLOAD_ERR_EXTENSION => 'O servidor bloqueou o envio da foto.',
        ];
        throw new RuntimeException($messages[$upload['error']] ?? 'Não foi possível receber a foto.');
    }

    $temporaryPath = $upload['tmp_name'] ?? '';
    $size = $upload['size'] ?? 0;
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath) || !is_int($size) || $size < 1 || $size > $maxBytes) {
        throw new RuntimeException('A foto deve ter até ' . (int) ($maxBytes / 1024 / 1024) . ' MB.');
    }
    if (!class_exists('finfo')) {
        throw new RuntimeException('O servidor precisa da extensão Fileinfo para validar fotos.');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fileInfo->file($temporaryPath);
    $image = @getimagesize($temporaryPath);
    if (
        !is_string($mime)
        || !isset($allowedTypes[$mime])
        || $image === false
        || !isset($image['mime'])
        || $image['mime'] !== $mime
        || $image[0] < 1
        || $image[1] < 1
        || $image[0] > 6000
        || $image[1] > 6000
    ) {
        throw new RuntimeException('A foto precisa ser válida e ter até 6000 px por lado. Confira os formatos aceitos.');
    }

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível acessar a pasta de fotos. Verifique as permissões do servidor.');
    }

    $protectionFile = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($protectionFile)) {
        $protection = "<FilesMatch \"\\.(php[0-9]?|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n";
        if (file_put_contents($protectionFile, $protection, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível proteger a pasta de fotos no servidor.');
        }
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mime];
    if (!move_uploaded_file($temporaryPath, $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Não foi possível salvar a foto. Verifique as permissões da pasta.');
    }

    return $filename;
}

function deleteStoredPhoto(string $photo, string $directory, string $allowedExtensions): void
{
    if (preg_match('/^[a-zA-Z0-9_-]+\.(?:' . $allowedExtensions . ')$/', $photo) !== 1) {
        return;
    }

    $path = $directory . DIRECTORY_SEPARATOR . $photo;
    if (is_file($path) && !unlink($path)) {
        error_log('Não foi possível remover foto: ' . $path);
    }
}

function loadAuthors(string $file): array
{
    $authors = loadJsonList($file, 'autores.json');
    foreach ($authors as $author) {
        if (
            !is_array($author)
            || !isset($author['id'], $author['nome'], $author['cidade'], $author['foto'])
            || !is_string($author['id'])
            || !is_string($author['nome'])
            || !is_string($author['cidade'])
            || !is_string($author['foto'])
            || (array_key_exists('mini_bio', $author) && !is_string($author['mini_bio']))
        ) {
            throw new RuntimeException('autores.json não corresponde ao formato esperado.');
        }
    }
    return $authors;
}

function loadArtists(string $file): array
{
    $artists = loadJsonList($file, 'artists.json');
    foreach ($artists as $artist) {
        if (
            !is_array($artist)
            || !isset($artist['filename'], $artist['description'])
            || !is_string($artist['filename'])
            || !is_string($artist['description'])
            || (isset($artist['name']) && !is_string($artist['name']))
        ) {
            throw new RuntimeException('artists.json não corresponde ao formato esperado.');
        }
    }
    return $artists;
}

function listArtistCards(string $directory, array $metadata, array $allowedMimeTypes): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $entries = scandir($directory);
    if ($entries === false) {
        throw new RuntimeException('Não foi possível listar as fotos dos artistas.');
    }

    $metadataByFilename = [];
    foreach ($metadata as $artist) {
        $metadataByFilename[$artist['filename']] = $artist;
    }

    $cards = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (!is_file($path) || !in_array($extension, $allowedMimeTypes, true)) {
            continue;
        }

        $artist = $metadataByFilename[$entry] ?? [];
        $name = isset($artist['name']) && trim($artist['name']) !== ''
            ? $artist['name']
            : ucwords(str_replace(['-', '_'], ' ', pathinfo($entry, PATHINFO_FILENAME)));
        $cards[] = [
            'filename' => $entry,
            'imagePath' => '/imgs/artists/' . rawurlencode($entry),
            'name' => $name,
            'description' => $artist['description'] ?? '',
        ];
    }

    return $cards;
}

if (!isset($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Método não permitido.');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'artists-list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode(
            listArtistCards($artistsDirectory, loadArtists($artistsFile), array_values($artistMimeTypes)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    } catch (Throwable $error) {
        error_log('Não foi possível carregar a lista pública de atrações: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível carregar as atrações.']);
    }
    exit;
}

$errorMessage = '';
$statusMessage = '';
$isAuthenticated = ($_SESSION['admin_authenticated'] ?? false) === true;
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $token = $_POST['csrf_token'] ?? '';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);

    if (!is_string($token) || !hash_equals($_SESSION['admin_csrf'], $token)) {
        http_response_code(400);
        $errorMessage = 'A sessão do formulário expirou. Atualize a página e tente novamente.';
    } elseif ($lockedUntil > time()) {
        http_response_code(429);
        $errorMessage = 'Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.';
    } elseif (
        is_string($username)
        && is_string($password)
        && hash_equals($adminUsername, $username)
        && password_verify($password, $adminPasswordHash)
    ) {
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['login_attempts'] = 0;
        unset($_SESSION['login_locked_until']);
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
        header('Location: admin.php', true, 303);
        exit;
    } else {
        $attempts++;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['login_locked_until'] = time() + 300;
            $_SESSION['login_attempts'] = 0;
        }
        http_response_code(401);
        $errorMessage = 'Usuário ou senha incorretos.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout' && $isAuthenticated) {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['admin_csrf'], $token)) {
        http_response_code(400);
        $errorMessage = 'A sessão expirou. Atualize a página e tente novamente.';
    } else {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        header('Location: admin.php', true, 303);
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAuthenticated) {
    http_response_code(401);
    $errorMessage = 'Entre no painel para continuar.';
}

$isAuthenticated = ($_SESSION['admin_authenticated'] ?? false) === true;

if ($isAuthenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'logout') {
    $newPhoto = '';
    $oldPhoto = '';
    $oldPhotoDirectory = '';
    $lock = fopen(__DIR__ . DIRECTORY_SEPARATOR . 'admin-content.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        http_response_code(500);
        $errorMessage = 'Não foi possível bloquear os dados para edição. Tente novamente.';
    } else {
        try {
            $token = $_POST['csrf_token'] ?? '';
            if (!is_string($token) || !hash_equals($_SESSION['admin_csrf'], $token)) {
                http_response_code(400);
                throw new RuntimeException('A sessão do formulário expirou. Atualize a página e tente novamente.');
            }

            if ($action === 'author_add' || $action === 'author_edit' || $action === 'author_delete') {
                $authors = loadAuthors($authorsFile);
                $id = $_POST['id'] ?? '';
                $index = null;
                if ($action !== 'author_add') {
                    if (!is_string($id) || preg_match('/^[a-f0-9]{16}$/', $id) !== 1) {
                        throw new RuntimeException('Identificador de autor inválido.');
                    }
                    foreach ($authors as $key => $author) {
                        if ($author['id'] === $id) {
                            $index = $key;
                            break;
                        }
                    }
                    if ($index === null) {
                        throw new RuntimeException('Esse autor não foi encontrado. Atualize o painel e tente novamente.');
                    }
                }

                if ($action === 'author_add') {
                    $name = validateText($_POST['nome'] ?? null, 'nome', 120, true);
                    $city = validateText($_POST['cidade'] ?? '', 'cidade ou país', 120, false);
                    $miniBio = validateText($_POST['mini_bio'] ?? '', 'mini-bio', 4000, false);
                    $photo = storeUploadedPhoto($_FILES['foto'] ?? [], $authorsDirectory, $maxAuthorUploadBytes, [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                    ]);
                    $newPhoto = $photo;
                    $authors[] = [
                        'id' => bin2hex(random_bytes(8)),
                        'nome' => $name,
                        'cidade' => $city,
                        'mini_bio' => $miniBio,
                        'foto' => $authorsPublicPath . $photo,
                    ];
                } elseif ($action === 'author_delete') {
                    $oldPhoto = $authors[$index]['foto'];
                    $oldPhotoDirectory = $authorsDirectory;
                    array_splice($authors, $index, 1);
                } else {
                    $authors[$index]['nome'] = validateText($_POST['nome'] ?? null, 'nome', 120, true);
                    $authors[$index]['cidade'] = validateText($_POST['cidade'] ?? '', 'cidade ou país', 120, false);
                    $authors[$index]['mini_bio'] = validateText($_POST['mini_bio'] ?? '', 'mini-bio', 4000, false);
                    $upload = $_FILES['foto'] ?? null;
                    if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $photo = storeUploadedPhoto($upload, $authorsDirectory, $maxAuthorUploadBytes, [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/webp' => 'webp',
                        ]);
                        $newPhoto = $photo;
                        $oldPhoto = $authors[$index]['foto'];
                        $oldPhotoDirectory = $authorsDirectory;
                        $authors[$index]['foto'] = $authorsPublicPath . $photo;
                    }
                }

                saveJsonList($authorsFile, $authors, 'autores.json');
            } elseif ($action === 'artist_add' || $action === 'artist_edit' || $action === 'artist_delete') {
                $artists = loadArtists($artistsFile);
                $filename = $_POST['filename'] ?? '';
                $index = null;
                if ($action !== 'artist_add') {
                    if (
                        !is_string($filename)
                        || basename($filename) !== $filename
                        || preg_match('/^[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp|avif)$/i', $filename) !== 1
                    ) {
                        throw new RuntimeException('Identificador de atração inválido.');
                    }
                    foreach ($artists as $key => $artist) {
                        if ($artist['filename'] === $filename) {
                            $index = $key;
                            break;
                        }
                    }
                    if (!is_file($artistsDirectory . DIRECTORY_SEPARATOR . $filename)) {
                        throw new RuntimeException('A foto dessa atração não foi encontrada.');
                    }
                }

                if ($action === 'artist_delete') {
                    if ($index !== null) {
                        array_splice($artists, $index, 1);
                    }
                    $oldPhoto = $filename;
                    $oldPhotoDirectory = $artistsDirectory;
                    saveJsonList($artistsFile, $artists, 'artists.json');
                } elseif ($action === 'artist_add') {
                    $name = validateText($_POST['name'] ?? null, 'nome do artista', 120, true);
                    $description = validateText($_POST['description'] ?? '', 'descrição', 1000, false);
                    $photo = storeUploadedPhoto($_FILES['photo'] ?? [], $artistsDirectory, $maxArtistUploadBytes, $artistMimeTypes);
                    $newPhoto = $photo;
                    $artists[] = [
                        'filename' => $photo,
                        'name' => $name,
                        'description' => $description,
                    ];
                    saveJsonList($artistsFile, $artists, 'artists.json');
                } else {
                    $name = validateText($_POST['name'] ?? null, 'nome do artista', 120, true);
                    $description = validateText($_POST['description'] ?? '', 'descrição', 1000, false);
                    if ($index === null) {
                        $artists[] = [
                            'filename' => $filename,
                            'name' => $name,
                            'description' => $description,
                        ];
                        $index = array_key_last($artists);
                    }
                    $artists[$index]['name'] = $name;
                    $artists[$index]['description'] = $description;
                    $upload = $_FILES['photo'] ?? null;
                    if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $photo = storeUploadedPhoto($upload, $artistsDirectory, $maxArtistUploadBytes, $artistMimeTypes);
                        $newPhoto = $photo;
                        $oldPhoto = $filename;
                        $oldPhotoDirectory = $artistsDirectory;
                        $artists[$index]['filename'] = $photo;
                    }
                    saveJsonList($artistsFile, $artists, 'artists.json');
                }
            } else {
                throw new RuntimeException('Ação inválida.');
            }

            flock($lock, LOCK_UN);
            fclose($lock);

            if ($oldPhoto !== '') {
                if ($oldPhotoDirectory === $authorsDirectory && strncmp($oldPhoto, $authorsPublicPath, strlen($authorsPublicPath)) === 0) {
                    deleteStoredPhoto(substr($oldPhoto, strlen($authorsPublicPath)), $authorsDirectory, 'jpg|png|webp');
                } elseif ($oldPhotoDirectory === $artistsDirectory) {
                    deleteStoredPhoto($oldPhoto, $artistsDirectory, 'jpg|jpeg|png|webp|avif');
                }
            }

            header('Location: admin.php?status=saved', true, 303);
            exit;
        } catch (Throwable $error) {
            if ($newPhoto !== '') {
                $directory = str_starts_with($action, 'artist_') ? $artistsDirectory : $authorsDirectory;
                deleteStoredPhoto($newPhoto, $directory, 'jpg|jpeg|png|webp|avif');
            }
            error_log('Erro ao atualizar conteúdo do painel: ' . $error->getMessage());
            if (http_response_code() < 400) {
                http_response_code(400);
            }
            $errorMessage = $error instanceof RuntimeException
                ? $error->getMessage()
                : 'Ocorreu um erro ao salvar. Consulte o log do servidor e tente novamente.';
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

if ($isAuthenticated && ($_GET['status'] ?? '') === 'saved') {
    $statusMessage = 'Alterações salvas.';
}

if ($isAuthenticated) {
    try {
        $authors = loadAuthors($authorsFile);
        $artistMetadata = loadArtists($artistsFile);
        $artists = listArtistCards($artistsDirectory, $artistMetadata, array_values($artistMimeTypes));
    } catch (Throwable $error) {
        error_log('Erro ao carregar painel de conteúdo: ' . $error->getMessage());
        $authors = $authors ?? [];
        $artists = $artists ?? [];
        if ($errorMessage === '') {
            $errorMessage = $error->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Painel de conteúdo | Fli Paraíba</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; background: #ededed; color: #242424; font: 16px/1.5 Arial, sans-serif; }
        main { width: min(100%, 1100px); margin: 0 auto; }
        h1, h2 { color: #70339a; }
        h1 { font-size: clamp(2rem, 6vw, 3.5rem); line-height: 1.05; }
        h2 { margin-top: 40px; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .notice { margin: 20px 0; padding: 14px 16px; border-left: 5px solid #ed0874; background: #fff; }
        .notice.error { border-color: #b42318; color: #8a1c13; }
        .form-card { margin: 16px 0; padding: 20px; background: #fff; box-shadow: 5px 6px 0 rgb(112 43 145 / 18%); }
        .form-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: 14px; }
        label { display: grid; gap: 6px; font-weight: 700; }
        input, textarea, button { font: inherit; }
        input, textarea { width: 100%; min-height: 44px; padding: 9px 10px; border: 1px solid #777; }
        input[type="file"] { padding: 7px; }
        textarea { min-height: 100px; resize: vertical; }
        button { min-height: 44px; margin: 14px 8px 0 0; padding: 9px 16px; border: 0; background: #ed0874; color: #fff; cursor: pointer; font-weight: 700; }
        button.delete, button.secondary { background: #702b91; }
        .author-edit, .artist-edit { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 18px; align-items: start; }
        .author-edit img, .artist-edit img { width: 120px; aspect-ratio: 4 / 5; object-fit: cover; }
        .help { margin: 8px 0 0; color: #555; font-size: .9rem; }
        .login-panel { width: min(100%, 440px); margin: 12vh auto 0; }
        .login-panel h1 { font-size: 2rem; }
        @media (max-width: 520px) {
            body { padding: 16px; }
            .topbar { align-items: flex-start; }
            .author-edit, .artist-edit { grid-template-columns: 1fr; }
            .author-edit img, .artist-edit img { width: 96px; }
        }
    </style>
</head>
<body>
<main>
    <?php if (!$isAuthenticated): ?>
        <section class="form-card login-panel" aria-labelledby="login-title">
            <h1 id="login-title">Acesso ao painel</h1>
            <p>Entre para gerenciar autores e atrações musicais.</p>
            <?php if ($errorMessage !== ''): ?>
                <p class="notice error" role="alert"><?= escapeHtml($errorMessage) ?></p>
            <?php endif; ?>
            <form action="admin.php" method="post">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['admin_csrf']) ?>">
                <div class="form-fields">
                    <label>Usuário
                        <input type="text" name="username" autocomplete="username" required>
                    </label>
                    <label>Senha
                        <input type="password" name="password" autocomplete="current-password" required>
                    </label>
                </div>
                <button type="submit">Entrar</button>
            </form>
        </section>
    <?php else: ?>
        <header class="topbar">
            <div>
                <h1>Painel de conteúdo</h1>
                <p>Gerencie autores confirmados e atrações musicais do carrossel.</p>
            </div>
            <form action="admin.php" method="post">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['admin_csrf']) ?>">
                <button class="secondary" type="submit">Sair</button>
            </form>
        </header>

        <?php if ($statusMessage !== ''): ?>
            <p class="notice" role="status"><?= escapeHtml($statusMessage) ?></p>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <p class="notice error" role="alert"><?= escapeHtml($errorMessage) ?></p>
        <?php endif; ?>

        <section aria-labelledby="artists-title">
            <h2 id="artists-title">Atrações musicais</h2>
            <p>Fotos e textos exibidos no carrossel da seção Shows. JPG, PNG, WEBP ou AVIF; até 8 MB e 6000 px por lado.</p>
            <form class="form-card" action="admin.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="artist_add">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxArtistUploadBytes ?>">
                <div class="form-fields">
                    <label>Nome do artista
                        <input type="text" name="name" maxlength="120" required>
                    </label>
                    <label>Descrição do evento
                        <textarea name="description" maxlength="1000"></textarea>
                    </label>
                    <label>Foto
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/avif" required>
                    </label>
                </div>
                <button type="submit">Adicionar atração</button>
            </form>

            <h3>Atrações cadastradas (<?= count($artists) ?>)</h3>
            <?php if ($artists === []): ?>
                <p>Nenhuma foto encontrada em imgs/artists.</p>
            <?php endif; ?>
            <?php foreach ($artists as $artist): ?>
                <article class="form-card artist-edit">
                    <img src="<?= escapeHtml($artist['imagePath']) ?>" alt="<?= escapeHtml($artist['name']) ?>" width="120" loading="lazy">
                    <div>
                        <form action="admin.php" method="post" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="artist_edit">
                            <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['admin_csrf']) ?>">
                            <input type="hidden" name="filename" value="<?= escapeHtml($artist['filename']) ?>">
                            <div class="form-fields">
                                <label>Nome do artista
                                    <input type="text" name="name" maxlength="120" value="<?= escapeHtml($artist['name']) ?>" required>
                                </label>
                                <label>Descrição do evento
                                    <textarea name="description" maxlength="1000"><?= escapeHtml($artist['description']) ?></textarea>
                                </label>
                                <label>Trocar foto (opcional)
                                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/avif">
                                </label>
                            </div>
                            <button type="submit">Salvar atração</button>
                        </form>
                        <form action="admin.php" method="post" onsubmit="return confirm('Excluir esta atração e sua foto?');">
                            <input type="hidden" name="action" value="artist_delete">
                            <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['admin_csrf']) ?>">
                            <input type="hidden" name="filename" value="<?= escapeHtml($artist['filename']) ?>">
                            <button class="delete" type="submit">Excluir atração</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <section aria-labelledby="authors-title">
            <h2 id="authors-title">Autores confirmados</h2>
            <p>As fotos aceitas são JPG, PNG ou WEBP, com até 5 MB.</p>
            <form class="form-card" action="admin.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="author_add">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxAuthorUploadBytes ?>">
                <div class="form-fields">
                    <label>Nome
                        <input type="text" name="nome" maxlength="120" required>
                    </label>
                    <label>Cidade ou país
                        <input type="text" name="cidade" maxlength="120">
                    </label>
                    <label>Mini-bio
                        <textarea name="mini_bio" maxlength="1000"></textarea>
                    </label>
                    <label>Foto
                        <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" required>
                    </label>
                </div>
                <button type="submit">Adicionar autor</button>
            </form>

            <h3>Autores cadastrados (<?= count($authors) ?>)</h3>
            <?php if ($authors === []): ?>
                <p>Nenhum autor cadastrado.</p>
            <?php endif; ?>
            <?php foreach ($authors as $author): ?>
                <article class="form-card author-edit">
                    <?php
                    $photoPath = $author['foto'];
                    $safePhotoPath = preg_match('/^autores_uploads\/[a-f0-9]{32}\.(?:jpg|png|webp)$/', $photoPath) === 1;
                    ?>
                    <?php if ($safePhotoPath): ?>
                        <img src="<?= escapeHtml($photoPath) ?>" alt="Foto atual de <?= escapeHtml($author['nome']) ?>" width="120" height="120" loading="lazy">
                    <?php endif; ?>
                    <div>
                        <form action="admin.php" method="post" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="author_edit">
                            <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['admin_csrf']) ?>">
                            <input type="hidden" name="id" value="<?= escapeHtml($author['id']) ?>">
                            <div class="form-fields">
                                <label>Nome
                                    <input type="text" name="nome" maxlength="120" value="<?= escapeHtml($author['nome']) ?>" required>
                                </label>
                                <label>Cidade ou país
                                    <input type="text" name="cidade" maxlength="120" value="<?= escapeHtml($author['cidade']) ?>">
                                </label>
                                <label>Mini-bio
                                    <textarea name="mini_bio" maxlength="1000"><?= escapeHtml($author['mini_bio'] ?? '') ?></textarea>
                                </label>
                                <label>Trocar foto (opcional)
                                    <input type="file" name="foto" accept="image/jpeg,image/png,image/webp">
                                </label>
                            </div>
                            <button type="submit">Salvar autor</button>
                        </form>
                        <form action="admin.php" method="post" onsubmit="return confirm('Excluir este autor?');">
                            <input type="hidden" name="action" value="author_delete">
                            <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['admin_csrf']) ?>">
                            <input type="hidden" name="id" value="<?= escapeHtml($author['id']) ?>">
                            <button class="delete" type="submit">Excluir autor</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
