<?php
require_once __DIR__ . '/config/database.php';
$publicPage = true;
$activeMenu = 'home';
$pageTitle = 'Buy & Sell Camera Gear';

// ---------------------------------------------------------------------------
// Search & filters, all handled on this page:
//   ?q=sony a6400&category=1&brand=2&condition=Mint&min=10000&max=50000&stock=1&sort=price_asc&page=2
// Every filter combines with every other one.
// ---------------------------------------------------------------------------
$conditions = ['Brand New', 'Mint', 'Excellent', 'Good', 'Fair', 'With defects'];
$sorts = ['relevance' => 'Best match', 'newest' => 'Newest', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low'];

$q     = trim(preg_replace('/\s+/', ' ', (string)($_GET['q'] ?? '')));
$q     = mb_substr($q, 0, 100);
$cat   = (int)($_GET['category'] ?? 0);
$brand = (int)($_GET['brand'] ?? 0);
$cond  = in_array($_GET['condition'] ?? '', $conditions, true) ? $_GET['condition'] : '';
$min   = (isset($_GET['min']) && is_numeric($_GET['min']) && $_GET['min'] > 0) ? (float)$_GET['min'] : null;
$max   = (isset($_GET['max']) && is_numeric($_GET['max']) && $_GET['max'] > 0) ? (float)$_GET['max'] : null;
if ($min !== null && $max !== null && $min > $max) { [$min, $max] = [$max, $min]; }
$stock = !empty($_GET['stock']);
$sort  = $_GET['sort'] ?? '';
if (!isset($sorts[$sort]) || ($sort === 'relevance' && $q === '')) $sort = ($q !== '') ? 'relevance' : 'newest';
$page  = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;

// Escape % and _ so user text is matched literally
$likeOf = fn($t) => '%' . addcslashes($t, '\\%_') . '%';

$where = ["pl.Status = 'Live'"]; $types = ''; $params = [];

// Each word must match somewhere (title, model, brand or category), in any order,
// so "sony a6400" finds "Sony Alpha A6400" and "a6400 sony" works too.
$tokens = $q !== '' ? array_slice(array_unique(explode(' ', mb_strtolower($q))), 0, 6) : [];
foreach ($tokens as $t) {
    $where[] = "(pl.Title LIKE ? OR pm.ModelName LIKE ? OR b.BrandName LIKE ? OR c.CategoryName LIKE ?)";
    $types .= 'ssss';
    array_push($params, $likeOf($t), $likeOf($t), $likeOf($t), $likeOf($t));
}
if ($cat)   { $where[] = "pm.CategoryID = ?"; $types .= 'i'; $params[] = $cat; }
if ($brand) { $where[] = "pm.BrandID = ?";    $types .= 'i'; $params[] = $brand; }
if ($cond !== '') { $where[] = "pl.`Condition` = ?"; $types .= 's'; $params[] = $cond; }
if ($min !== null) { $where[] = "pl.Price >= ?"; $types .= 'd'; $params[] = $min; }
if ($max !== null) { $where[] = "pl.Price <= ?"; $types .= 'd'; $params[] = $max; }
if ($stock) { $where[] = "pl.Quantity > 0"; }
$whereSql = implode(' AND ', $where);

$filtering = ($q !== '' || $cat || $brand || $cond !== '' || $min !== null || $max !== null || $stock);

$fromSql = "FROM product_listing pl
            INNER JOIN product_model pm ON pl.ModelID = pm.ModelID
            INNER JOIN brand b ON pm.BrandID = b.BrandID
            LEFT JOIN category c ON pm.CategoryID = c.CategoryID";

// Total, for the result count and pagination
$st = $conn->prepare("SELECT COUNT(*) $fromSql WHERE $whereSql");
if ($types !== '') $st->bind_param($types, ...$params);
$st->execute();
$total = (int)$st->get_result()->fetch_row()[0];
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

// Ordering. "Best match" ranks exact title/model/brand hits above partial ones.
$selTypes = ''; $selParams = []; $score = '0';
if ($sort === 'relevance') {
    $score = "(CASE WHEN pl.Title LIKE ? THEN 4 ELSE 0 END)
            + (CASE WHEN pm.ModelName LIKE ? THEN 3 ELSE 0 END)
            + (CASE WHEN b.BrandName LIKE ? THEN 2 ELSE 0 END)
            + (CASE WHEN pl.Title LIKE ? THEN 1 ELSE 0 END)";
    $phrase = $likeOf(mb_strtolower($q)); $first = $likeOf($tokens[0] ?? '');
    $selTypes = 'ssss'; $selParams = [$phrase, $phrase, $phrase, $first];
}
$orderSql = [
    'relevance' => 'Score DESC, pl.ListingID DESC',
    'newest'    => 'pl.ListingID DESC',
    'price_asc' => 'pl.Price ASC, pl.ListingID DESC',
    'price_desc'=> 'pl.Price DESC, pl.ListingID DESC',
][$sort];

$sql = "SELECT pl.*, pm.ModelName, b.BrandName, c.CategoryName, $score AS Score,
        (SELECT ImageURL FROM listing_image li WHERE li.ListingID = pl.ListingID ORDER BY IsPrimary DESC, DisplayOrder ASC LIMIT 1) AS PrimaryImage
        $fromSql WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset";
$st = $conn->prepare($sql);
$allTypes = $selTypes . $types; $allParams = array_merge($selParams, $params);
if ($allTypes !== '') $st->bind_param($allTypes, ...$allParams);
$st->execute();
$listings = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$cats = $conn->query("SELECT CategoryID, CategoryName FROM category WHERE Status = 'Active' ORDER BY CategoryName")->fetch_all(MYSQLI_ASSOC);
$brands = $conn->query("SELECT BrandID, BrandName FROM brand WHERE Status = 'Active' ORDER BY BrandName")->fetch_all(MYSQLI_ASSOC);

// --- URL helpers -----------------------------------------------------------
$state = ['q' => $q, 'category' => $cat ?: '', 'brand' => $brand ?: '', 'condition' => $cond,
          'min' => $min ?? '', 'max' => $max ?? '', 'stock' => $stock ? 1 : '', 'sort' => $sort];
// Link that keeps the current filters but changes some (pass null/'' to remove one)
$link = function (array $change = []) use ($state, $q) {
    $s = array_merge($state, $change);
    if (!array_key_exists('page', $change)) $s['page'] = '';
    if ($s['sort'] === ($s['q'] !== '' ? 'relevance' : 'newest')) $s['sort'] = ''; // default, keep URLs tidy
    $s = array_filter($s, fn($v) => $v !== '' && $v !== null && $v !== 0);
    return '?' . http_build_query($s);
};
$nameOf = function (array $rows, $idKey, $nameKey, $id) { foreach ($rows as $r) if ((int)$r[$idKey] === (int)$id) return $r[$nameKey]; return ''; };

// Removable "active filter" pills
$pills = [];
if ($q !== '')   $pills[] = ['Search: “' . $q . '”', $link(['q' => '', 'sort' => ''])];
if ($cat)        $pills[] = ['Category: ' . $nameOf($cats, 'CategoryID', 'CategoryName', $cat), $link(['category' => ''])];
if ($brand)      $pills[] = ['Brand: ' . $nameOf($brands, 'BrandID', 'BrandName', $brand), $link(['brand' => ''])];
if ($cond !== '')$pills[] = ['Condition: ' . $cond, $link(['condition' => ''])];
if ($min !== null || $max !== null) {
    $lbl = $min !== null && $max !== null ? '₱' . number_format($min) . ' – ₱' . number_format($max)
         : ($min !== null ? '₱' . number_format($min) . ' and up' : 'Up to ₱' . number_format($max));
    $pills[] = ['Price: ' . $lbl, $link(['min' => '', 'max' => ''])];
}
if ($stock)      $pills[] = ['In stock only', $link(['stock' => ''])];

require __DIR__ . '/includes/header.php';
?>
<form class="kp-search mb-3" method="get" role="search">
    <section class="kp-hero text-center">
        <h1 class="fw-bold">Buy &amp; sell camera gear in the Philippines</h1>
        <p class="mb-3">Cameras, lenses and accessories, all in one place.</p>
        <div class="kp-hero-search mx-auto">
            <input type="search" name="q" class="form-control form-control-lg" value="<?php echo sanitize($q); ?>" maxlength="100" aria-label="Search listings" placeholder="Try &quot;Sony A6400&quot; or &quot;50mm lens&quot;">
            <button class="btn btn-warning btn-lg"><i class="bi bi-search"></i> Search</button>
        </div>
    </section>

    <div class="kp-filterbar">
        <div>
            <label class="form-label" for="f-cat">Category</label>
            <select id="f-cat" name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All categories</option>
                <?php foreach ($cats as $c): ?><option value="<?php echo (int)$c['CategoryID']; ?>" <?php echo $cat === (int)$c['CategoryID'] ? 'selected' : ''; ?>><?php echo sanitize($c['CategoryName']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="f-brand">Brand</label>
            <select id="f-brand" name="brand" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All brands</option>
                <?php foreach ($brands as $b): ?><option value="<?php echo (int)$b['BrandID']; ?>" <?php echo $brand === (int)$b['BrandID'] ? 'selected' : ''; ?>><?php echo sanitize($b['BrandName']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="f-cond">Condition</label>
            <select id="f-cond" name="condition" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Any condition</option>
                <?php foreach ($conditions as $c): ?><option <?php echo $cond === $c ? 'selected' : ''; ?>><?php echo sanitize($c); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="kp-price-field">
            <label class="form-label" for="f-min">Price (₱)</label>
            <div class="input-group input-group-sm">
                <input id="f-min" type="number" name="min" min="0" step="any" inputmode="numeric" class="form-control" placeholder="Min" value="<?php echo sanitize($min ?? ''); ?>" onchange="this.form.submit()">
                <input type="number" name="max" min="0" step="any" inputmode="numeric" class="form-control" placeholder="Max" aria-label="Maximum price" value="<?php echo sanitize($max ?? ''); ?>" onchange="this.form.submit()">
            </div>
        </div>
        <div>
            <label class="form-label" for="f-sort">Sort by</label>
            <select id="f-sort" name="sort" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php $defSort = $q !== '' ? 'relevance' : 'newest'; ?>
                <option value=""><?php echo $sorts[$defSort]; ?></option>
                <?php foreach ($sorts as $k => $label): if ($k === 'relevance' || $k === 'newest') continue; ?>
                    <option value="<?php echo $k; ?>" <?php echo $sort === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
                <?php if ($q !== ''): ?><option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>><?php echo $sorts['newest']; ?></option><?php endif; ?>
            </select>
        </div>
        <div class="form-check kp-stock">
            <input class="form-check-input" type="checkbox" id="f-stock" name="stock" value="1" <?php echo $stock ? 'checked' : ''; ?> onchange="this.form.submit()">
            <label class="form-check-label" for="f-stock">In stock</label>
        </div>
    </div>
</form>

<?php if ($pills): ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <?php foreach ($pills as [$label, $href]): ?>
        <a class="kp-pill" href="<?php echo $href; ?>" title="Remove this filter"><?php echo sanitize($label); ?> <i class="bi bi-x-lg"></i></a>
    <?php endforeach; ?>
    <a class="text-warning small ms-1" href="<?php echo base_url(); ?>">Clear all</a>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-end mb-2">
    <h4 class="mb-0"><?php echo $filtering ? 'Results' : 'Latest listings'; ?></h4>
    <span class="text-white-50 small" aria-live="polite"><?php echo number_format($total); ?> listing<?php echo $total === 1 ? '' : 's'; ?></span>
</div>
<?php if ($listings): ?>
<div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3 mb-5">
    <?php foreach ($listings as $r): ?>
    <div class="col">
        <a href="<?php echo base_url('product_listing/view.php?id=' . (int)$r['ListingID']); ?>" class="kp-card text-decoration-none d-block h-100">
            <div class="kp-card-img"><img src="<?php echo sanitize(image_url($r['PrimaryImage'] ?? '')); ?>" alt="<?php echo sanitize($r['Title']); ?>" loading="lazy"></div>
            <div class="p-2 px-3 pb-3">
                <div class="small text-muted kp-meta"><?php echo sanitize($r['BrandName']); ?> &middot; <?php echo sanitize($r['ModelName']); ?></div>
                <div class="fw-semibold text-dark kp-title"><?php echo sanitize($r['Title']); ?></div>
                <div class="kp-price mt-1"><?php echo format_price($r['Price']); ?></div>
                <div class="mt-2"><?php echo condition_badge($r['Condition']); ?></div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php if ($pages > 1): ?>
<nav class="mb-5" aria-label="Result pages"><ul class="pagination justify-content-center flex-wrap">
    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $link(['page' => $page - 1]); ?>">&laquo; Prev</a></li>
    <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo $link(['page' => $i]); ?>"><?php echo $i; ?></a></li>
    <?php endfor; ?>
    <li class="page-item <?php echo $page >= $pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $link(['page' => $page + 1]); ?>">Next &raquo;</a></li>
</ul></nav>
<?php endif; ?>
<?php else: ?>
<div class="kp-empty text-center mb-5">
    <i class="bi bi-search fs-1"></i>
    <h5 class="mt-2"><?php echo $filtering ? 'No listings match your search' : 'No live listings yet'; ?></h5>
    <?php if ($filtering): ?>
    <p class="mb-3 text-white-50">Try fewer words, check the spelling, or remove a filter.</p>
    <div class="d-flex flex-wrap gap-2 justify-content-center">
        <?php foreach ($pills as [$label, $href]): ?><a class="kp-pill" href="<?php echo $href; ?>">Remove: <?php echo sanitize($label); ?></a><?php endforeach; ?>
        <a class="btn btn-sm btn-warning" href="<?php echo base_url(); ?>">Clear all</a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
