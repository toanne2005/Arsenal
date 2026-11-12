<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/bootstrap.php';
arsenal_session_start();

function arsenal_ai_words(string $value): array
{
    $value = mb_strtolower($value, 'UTF-8');
    preg_match_all('/[\p{L}\p{N}]+/u', $value, $matches);
    $ignored = [
        'cho', 'cua', 'giup', 'minh', 'toi', 'ban', 'la', 'va', 'voi', 'mot', 'nhung',
        'cac', 'co', 'nao', 'gi', 'the', 'hang', 'san', 'pham', 'tu', 'van', 'muon',
        'can', 'duoc', 'khong', 'bao', 'nhieu', 'xin', 'hay', 'loai', 'dong', 'mau',
    ];
    $words = [];
    foreach ($matches[0] as $word) {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $word);
        $word = is_string($normalized) ? strtolower($normalized) : $word;
        if (strlen($word) > 1 && !in_array($word, $ignored, true)) {
            $words[$word] = true;
        }
    }

    return array_keys($words);
}

function arsenal_ai_normalize(string $value): string
{
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($value, 'UTF-8'));

    return is_string($normalized) ? strtolower($normalized) : strtolower($value);
}

function arsenal_ai_overlap(array $questionWords, string $text): float
{
    if (!$questionWords) {
        return 0.0;
    }
    $textWords = arsenal_ai_words($text);
    if (!$textWords) {
        return 0.0;
    }
    $matches = count(array_intersect($questionWords, $textWords));

    return $matches / count($questionWords);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        arsenal_json_response(['csrfToken' => arsenal_csrf_token()]);
    }

    arsenal_request_method('POST');
    $payload = arsenal_read_json();
    arsenal_require_csrf($payload);
    $question = trim((string)($payload['question'] ?? ''));
    if ($question === '' || mb_strlen($question, 'UTF-8') > 500) {
        throw new InvalidArgumentException('Câu hỏi cần có từ 1 đến 500 ký tự.');
    }

    $now = time();
    $recentRequests = array_values(array_filter(
        $_SESSION['arsenal_ai_requests'] ?? [],
        static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - 60
    ));
    if (count($recentRequests) >= 12) {
        arsenal_json_response(['message' => 'Bạn gửi hơi nhanh. Vui lòng đợi một phút rồi thử lại.'], 429);
    }
    $recentRequests[] = $now;
    $_SESSION['arsenal_ai_requests'] = $recentRequests;

    $db = arsenal_db();
    $rules = $db->query(
        "SELECT maQuyTacAI AS id, loaiCauHinh AS type, tenMuc AS name,
                trinhDo AS level, nganSachTu AS budgetFrom, nganSachDen AS budgetTo,
                doPhucTapToiDa AS maxComplexity, noiDungQuyTac AS ruleContent,
                cauHoiFAQ AS faqQuestion, cauTraLoiFAQ AS faqAnswer,
                doUuTien AS priority, maDong AS seriesId
         FROM tblQuyTacGoiYAI
         WHERE kichHoat=1 AND loaiCauHinh IN ('FAQ', 'GOI_Y')
         ORDER BY doUuTien DESC, tenMuc"
    )->fetchAll();
    $products = $db->query(
        "SELECT sp.maSP AS id, sp.tenSP AS name, sp.giaBan AS price,
                sp.tonKhaDung AS stock, sp.anhDaiDienUrl AS image,
                sp.tyLe AS scale, sp.doPhucTapLapRap AS complexity,
                dm.tenDanhMuc AS category, ph.kyHieu AS gradeCode,
                ph.tenPhanHang AS grade, dong.tenDong AS series,
                sp.maDong AS seriesId
         FROM tblSanPham sp
         INNER JOIN tblDanhMuc dm ON dm.maDM=sp.maDM AND dm.hoatDong=1
         LEFT JOIN tblPhanHangGunpla ph ON ph.maPhanHang=sp.maPhanHang AND ph.hoatDong=1
         LEFT JOIN tblDongMecha dong ON dong.maDong=sp.maDong AND dong.hoatDong=1
         WHERE sp.trangThai='DANG_BAN' AND sp.tonKhaDung>0
         ORDER BY sp.uuTienGoiY DESC, sp.themLuc DESC, sp.tenSP"
    )->fetchAll();

    $questionWords = arsenal_ai_words($question);
    $selectedRule = null;
    $bestFaqScore = 0.0;
    foreach ($rules as $rule) {
        if ($rule['type'] !== 'FAQ' || trim((string)$rule['faqQuestion']) === '') {
            continue;
        }
        $faqText = (string)$rule['faqQuestion'];
        $score = arsenal_ai_overlap($questionWords, $faqText);
        $normalizedQuestion = arsenal_ai_normalize($question);
        if (str_contains($normalizedQuestion, arsenal_ai_normalize($faqText))) {
            $score = 1.0;
        }
        if ($score > $bestFaqScore) {
            $bestFaqScore = $score;
            $selectedRule = $rule;
        }
    }

    $gradeFilter = null;
    foreach (['HG', 'RG', 'MG', 'PG'] as $gradeCode) {
        if (preg_match('/\b' . $gradeCode . '\b/i', $question)) {
            $gradeFilter = $gradeCode;
            break;
        }
    }
    $normalizedQuestion = arsenal_ai_normalize($question);
    $toolIntent = (bool)preg_match('/dung cu|kim|dao|dua|nhip|son|phu kien|tham cat/i', $normalizedQuestion);
    $budget = null;
    if (preg_match('/(?:duoi|toi da|tam gia|ngan sach|khoang|gia)\s*(\d[\d.,]*)\s*(trieu|tr|m|k|nghin|ngan)?/i', $normalizedQuestion, $budgetMatch)) {
        $amount = (float)str_replace([',', '.'], '', $budgetMatch[1]);
        $unit = mb_strtolower((string)($budgetMatch[2] ?? ''), 'UTF-8');
        if (in_array($unit, ['trieu', 'tr', 'm'], true)) {
            $amount *= 1000000;
        } elseif (in_array($unit, ['k', 'nghin', 'ngan'], true)) {
            $amount *= 1000;
        }
        if ($amount > 0) {
            $budget = $amount;
        }
    }
    $level = null;
    if (preg_match('/nguoi moi|moi bat dau|de lap|don gian/i', $normalizedQuestion)) {
        $level = 'NGUOI_MOI';
    } elseif (preg_match('/trung cap/i', $normalizedQuestion)) {
        $level = 'TRUNG_CAP';
    } elseif (preg_match('/nang cao|phuc tap/i', $normalizedQuestion)) {
        $level = 'NANG_CAO';
    }

    $matchedRecommendationRule = null;
    foreach ($rules as $rule) {
        if ($rule['type'] !== 'GOI_Y') {
            continue;
        }
        if ($level !== null && $rule['level'] !== null && $rule['level'] !== $level) {
            continue;
        }
        if ($budget !== null
            && (($rule['budgetFrom'] !== null && $budget < (float)$rule['budgetFrom'])
                || ($rule['budgetTo'] !== null && $budget > (float)$rule['budgetTo']))) {
            continue;
        }
        $score = arsenal_ai_overlap($questionWords, (string)$rule['name']);
        if ($level !== null && $rule['level'] === $level) {
            $score += 1.0;
        }
        if ($score > 0 && ($matchedRecommendationRule === null || $score > $matchedRecommendationRule['_score'])) {
            $matchedRecommendationRule = $rule;
            $matchedRecommendationRule['_score'] = $score;
        }
    }

    $recommended = [];
    $recommendationIntent = (bool)preg_match('/goi y|tim|chon|mua|mo hinh|model|san pham/i', $normalizedQuestion);
    foreach ($products as $product) {
        if ($gradeFilter !== null && strtoupper((string)$product['gradeCode']) !== $gradeFilter) {
            continue;
        }
        if ($budget !== null && (float)$product['price'] > $budget) {
            continue;
        }
        if ($toolIntent && !preg_match('/dung cu|kim|dao|dua|nhip|co|son|thảm|mat/i', (string)$product['category'])) {
            continue;
        }
        if ($matchedRecommendationRule !== null) {
            $rule = $matchedRecommendationRule;
            if ($rule['seriesId'] !== null && (int)$product['seriesId'] !== (int)$rule['seriesId']) {
                continue;
            }
            if ($rule['maxComplexity'] !== null && $product['complexity'] !== null
                && (int)$product['complexity'] > (int)$rule['maxComplexity']) {
                continue;
            }
            if ($rule['budgetFrom'] !== null && (float)$product['price'] < (float)$rule['budgetFrom']) {
                continue;
            }
            if ($rule['budgetTo'] !== null && (float)$product['price'] > (float)$rule['budgetTo']) {
                continue;
            }
        }
        $searchText = implode(' ', [
            $product['name'], $product['category'], $product['grade'] ?? '',
            $product['gradeCode'] ?? '', $product['series'] ?? '',
        ]);
        $score = arsenal_ai_overlap($questionWords, $searchText);
        if ($gradeFilter !== null && strtoupper((string)$product['gradeCode']) === $gradeFilter) {
            $score += 1.0;
        }
        if ($toolIntent) {
            $score += 0.5;
        }
        if ($matchedRecommendationRule !== null) {
            $score += 0.25;
        }
        if ($recommendationIntent && $score === 0.0) {
            $score = 0.1;
        }
        if ($score > 0) {
            $product['_score'] = $score;
            $recommended[] = $product;
        }
    }
    usort($recommended, static fn(array $left, array $right): int => $right['_score'] <=> $left['_score']);
    $recommended = array_slice($recommended, 0, 3);
    $recommended = array_map(static function (array $product): array {
        unset($product['_score'], $product['seriesId'], $product['complexity']);
        $product['price'] = (float)$product['price'];
        $product['stock'] = (int)$product['stock'];

        return $product;
    }, $recommended);

    if ($selectedRule !== null && $bestFaqScore >= 0.34) {
        $answer = trim((string)$selectedRule['faqAnswer']);
        $ruleId = (int)$selectedRule['id'];
        $source = 'DU_PHONG';
    } elseif ($recommended) {
        $answer = $matchedRecommendationRule !== null
            ? 'Theo tiêu chí “' . $matchedRecommendationRule['name'] . '”, đây là một số lựa chọn phù hợp đang còn hàng:'
            : 'Dựa trên sản phẩm và tiêu chí bạn nêu, bạn có thể tham khảo các lựa chọn sau:';
        $ruleId = $matchedRecommendationRule === null ? null : (int)$matchedRecommendationRule['id'];
        $source = 'DU_PHONG';
    } else {
        $answer = 'Mình chưa tìm thấy lựa chọn phù hợp trong sản phẩm đang còn hàng. Bạn cho biết thêm dòng Mecha, phân hạng (HG/MG/RG/PG), dụng cụ cần tìm hoặc ngân sách nhé.';
        $ruleId = null;
        $source = 'DU_PHONG';
    }

    $db->prepare(
        'INSERT INTO tblLichSuTuVanAI
         (taoLuc, cauHoiDaLoc, phanHoiDaLoc, sanPhamGoiYSnapshot, nguonPhanHoi, maQuyTacAI)
         VALUES (UTC_TIMESTAMP(3), :question, :answer, :products, :source, :ruleId)'
    )->execute([
        'question' => $question,
        'answer' => $answer,
        'products' => json_encode($recommended, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'source' => $source,
        'ruleId' => $ruleId,
    ]);

    arsenal_json_response(['answer' => $answer, 'products' => $recommended]);
} catch (InvalidArgumentException $error) {
    arsenal_json_response(['message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('ARSENAL AI consultation: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể xử lý tư vấn lúc này. Vui lòng thử lại sau.'], 500);
}
