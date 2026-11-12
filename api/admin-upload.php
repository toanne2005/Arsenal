<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/bootstrap.php';

function arsenal_upload_size_bytes(string $value): int
{
    if (!preg_match('/^\s*(\d+)\s*([KMG]?)\s*$/i', $value, $matches)) {
        return PHP_INT_MAX;
    }
    $number = (int)$matches[1];
    $factor = match (strtoupper($matches[2])) {
        'G' => 1024 * 1024 * 1024,
        'M' => 1024 * 1024,
        'K' => 1024,
        default => 1,
    };

    return $number > intdiv(PHP_INT_MAX, $factor) ? PHP_INT_MAX : $number * $factor;
}

function arsenal_uploaded_images(array $upload, string $fieldName, bool $multiple, array &$savedPaths, string $uploadDirectory = 'products'): array
{
    if ($upload === []) {
        return [];
    }

    $files = [];
    if ($multiple) {
        if (!isset($upload['name'], $upload['tmp_name'], $upload['error'], $upload['size'])
            || !is_array($upload['name']) || !is_array($upload['tmp_name'])
            || !is_array($upload['error']) || !is_array($upload['size'])) {
            throw new InvalidArgumentException('Dữ liệu tệp ảnh không hợp lệ.');
        }
        foreach ($upload['name'] as $index => $name) {
            $files[] = [
                'name' => $name,
                'tmp_name' => $upload['tmp_name'][$index] ?? '',
                'error' => $upload['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $upload['size'][$index] ?? 0,
            ];
        }
    } elseif (isset($upload['error']) && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
        $files[] = $upload;
    }

    if (count($files) > ($multiple ? 19 : 1)) {
        throw new InvalidArgumentException('Mỗi lần chỉ được tải tối đa 19 ảnh bổ sung.');
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $uploadDirectory;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Không tạo được thư mục lưu ảnh sản phẩm.');
    }
    $realDirectory = realpath($directory);
    if ($realDirectory === false || !is_writable($realDirectory)) {
        throw new RuntimeException('Thư mục ảnh sản phẩm không có quyền ghi.');
    }

    $basePath = dirname(dirname(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/api/admin-upload.php'))));
    $basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');

    foreach ($files as $file) {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ảnh vượt quá giới hạn tải lên của máy chủ.',
                UPLOAD_ERR_PARTIAL => 'Ảnh chỉ được tải lên một phần. Hãy thử lại.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Máy chủ chưa sẵn sàng lưu ảnh.',
                default => 'Không thể nhận tệp ảnh đã chọn.',
            });
        }

        $temporaryPath = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > 5 * 1024 * 1024 || !is_uploaded_file($temporaryPath)) {
            throw new InvalidArgumentException('Mỗi ảnh phải có dung lượng từ 1 byte đến 5 MB.');
        }
        $imageInfo = @getimagesize($temporaryPath);
        if ($imageInfo === false || $imageInfo[0] < 1 || $imageInfo[1] < 1
            || $imageInfo[0] > 8000 || $imageInfo[1] > 8000
            || $imageInfo[0] * $imageInfo[1] > 40000000) {
            throw new InvalidArgumentException('Tệp tải lên không phải ảnh hợp lệ hoặc kích thước ảnh quá lớn.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('Không thể kiểm tra định dạng ảnh trên máy chủ.');
        }
        $mime = finfo_file($finfo, $temporaryPath);
        finfo_close($finfo);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if ($extension === null || ($imageInfo['mime'] ?? '') !== $mime) {
            throw new InvalidArgumentException('Chỉ chấp nhận ảnh JPEG, PNG hoặc WebP.');
        }

        $filename = bin2hex(random_bytes(20)) . '.' . $extension;
        $destination = $realDirectory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($temporaryPath, $destination)) {
            throw new RuntimeException('Không lưu được ảnh trên máy chủ.');
        }
        $savedPaths[] = $destination;
        $files[$fieldName][] = $basePath . '/uploads/' . $uploadDirectory . '/' . $filename;
    }

    return $files[$fieldName] ?? [];
}

$savedPaths = [];
try {
    arsenal_request_method('POST');
    arsenal_session_start();
    $hasSeriesImage = isset($_FILES['seriesImage'])
        && (int)($_FILES['seriesImage']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $galleryErrors = $_FILES['galleryImages']['error'] ?? [];
    $hasProductImages = (isset($_FILES['mainImage']) && (int)($_FILES['mainImage']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)
        || (is_array($galleryErrors) && array_filter($galleryErrors, static fn($error) => (int)$error !== UPLOAD_ERR_NO_FILE) !== []);
    $permissions = [];
    if ($hasProductImages || !$hasSeriesImage) {
        $permissions[] = 'SAN_PHAM';
    }
    if ($hasSeriesImage) {
        $permissions[] = 'DANH_MUC';
    }
    arsenal_require_admin($permissions);
    arsenal_require_csrf();
    $requestLimit = min(20 * 1024 * 1024, arsenal_upload_size_bytes((string)ini_get('post_max_size')));
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $requestLimit) {
        arsenal_json_response(['message' => 'Tổng dung lượng ảnh vượt giới hạn tải lên của máy chủ.'], 413);
    }

    $mainImages = arsenal_uploaded_images($_FILES['mainImage'] ?? [], 'mainImage', false, $savedPaths);
    $galleryImages = arsenal_uploaded_images($_FILES['galleryImages'] ?? [], 'galleryImages', true, $savedPaths);
    $seriesImages = arsenal_uploaded_images($_FILES['seriesImage'] ?? [], 'seriesImage', false, $savedPaths, 'series');
    if ($mainImages === [] && $galleryImages === [] && $seriesImages === []) {
        throw new InvalidArgumentException('Hãy chọn ít nhất một ảnh để tải lên.');
    }
    arsenal_json_response([
        'mainImage' => $mainImages[0] ?? null,
        'galleryImages' => $galleryImages,
        'seriesImage' => $seriesImages[0] ?? null,
    ]);
} catch (InvalidArgumentException $error) {
    foreach ($savedPaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    arsenal_json_response(['message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    foreach ($savedPaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    error_log('ARSENAL admin image upload: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể tải ảnh sản phẩm lên.'], 500);
}
