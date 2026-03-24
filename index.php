<?php
ob_start();

//bagus k
require_once 'init_session.php';
require_once 'config.php';
require_once __DIR__ . '/vendor/autoload.php';

// --- 1. DEFINISIKAN ACTION DULU (WAJIB DI PALING ATAS) ---
$action = $_GET['action'] ?? 'home';

// --- 2. LOGIKA RESET TREE ---
if ($action === 'reset_tree') {
    unset($_SESSION['current_tree_id']);
    unset($_SESSION['current_tree_name']);
    session_write_close();
    header("Location: ?action=home");
    exit;
}

// --- LOGIKA LOGOUT ---
if ($action === 'logout') {
    $_SESSION = [];
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

// --- CEK LOGIN ---
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Cek Admin
$myUserId = $_SESSION['user_id'] ?? 0;
$myRole   = $_SESSION['role'] ?? 'user';
$isAdmin  = ($myRole === 'admin');

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Koneksi database
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_errno) die('Gagal konek database: ' . $mysqli->connect_error);
$mysqli->set_charset('utf8mb4');
if ($mysqli->connect_errno) die('Gagal konek database: ' . $mysqli->connect_error);
$mysqli->set_charset('utf8mb4');

// ... (Baris 62)
$mysqli->set_charset('utf8mb4');

// --- 6. AJAX GET COLLABORATORS (REVISI ANTI-ERROR 500) ---
if ($action === 'get_collaborators') {
    // 1. Bersihkan buffer output
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    
    // 2. Matikan display error agar tidak merusak JSON, tapi log errornya
    ini_set('display_errors', 0);
    
    $tid = intval($_GET['tree_id'] ?? 0);
    $rows = [];
    $myId = $_SESSION['user_id'] ?? 0;

    if ($myId == 0) { echo json_encode([]); exit; }

    // 3. Cek Otorisasi (Owner)
    $isAuthorized = false;

    $stmtAuth = $mysqli->prepare("SELECT id FROM family_trees WHERE id = ? AND user_id = ?");
    $stmtAuth->bind_param('ii', $tid, $myId);
    $stmtAuth->execute();
    $resultAuth = $stmtAuth->get_result();
    if ($resultAuth && $resultAuth->num_rows > 0) {
        $isAuthorized = true;
    }
    $stmtAuth->close();

    if (!$isAuthorized) {
        $stmtAuth2 = $mysqli->prepare("SELECT id FROM tree_collaborators WHERE tree_id = ? AND user_id = ?");
        $stmtAuth2->bind_param('ii', $tid, $myId);
        $stmtAuth2->execute();
        $resultAuth2 = $stmtAuth2->get_result();
        if ($resultAuth2 && $resultAuth2->num_rows > 0) {
            $isAuthorized = true;
        }
        $stmtAuth2->close();
    }

    if ($isAuthorized) {
        $stmt = $mysqli->prepare("SELECT c.user_id, u.name, u.email
                FROM tree_collaborators c
                JOIN users u ON u.id = c.user_id
                WHERE c.tree_id = ?");
        $stmt->bind_param('i', $tid);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['initial'] = strtoupper(substr($r['name'], 0, 1));
                $rows[] = $r;
            }
        }
        $stmt->close();
    }

    echo json_encode($rows);
    exit;
}

// --- 7. AJAX GET VIEWABLE TREES (Admin Panel) ---
if ($action === 'get_viewable_trees' && $isAdmin) {
    // 1. Bersihkan buffer output
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    // 2. Matikan display error
    ini_set('display_errors', 0);

    $targetId = intval($_GET['user_id'] ?? 0);
    $rows = [];

    if ($targetId > 0) {
        $stmt = $mysqli->prepare("SELECT id, name FROM family_trees WHERE user_id = ? AND allow_admin_view = 1 ORDER BY name ASC");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }

        $stmt->close();
    }

    echo json_encode($rows);
    exit;
}


// --- LOGIKA AKTIVASI AKUN (Saat diklik dari email) ---
if ($action === 'activate') {
    $activationCode = $_GET['code'] ?? '';
    
    // Matikan output buffering agar tidak merusak header redirect jika error
    while (ob_get_level()) { ob_end_clean(); }

    // Koneksi ke DB lokal untuk proses aktivasi
    $mysqli_local = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    
    if (!empty($activationCode)) {
        // Cari user berdasarkan kode aktivasi dan pastikan is_active=0
        $stmt = $mysqli_local->prepare("SELECT id, email FROM users WHERE activation_code = ? AND is_active = 0");
        $stmt->bind_param("s", $activationCode);
        $stmt->execute();
        $result = $stmt->get_result();
        $u = $result->fetch_assoc();
        $stmt->close();
        
        if ($u) {
            // AKTIFKAN AKUN
            $userId = $u['id'];
            $stmtUpdate = $mysqli_local->prepare("UPDATE users SET is_active = 1, activation_code = NULL WHERE id = ?");
            $stmtUpdate->bind_param('i', $userId);
            $stmtUpdate->execute();
            $stmtUpdate->close();

            $_SESSION['status_success'] = "Akun Anda berhasil diaktifkan kembali! Anda kini dapat login.";
        } else {
            $_SESSION['status_error'] = "Gagal: Kode aktivasi tidak valid atau akun sudah aktif.";
        }
    } else {
        $_SESSION['status_error'] = "Kode aktivasi tidak ditemukan.";
    }
    
    $mysqli_local->close();
    // Redirect ke halaman login (agar session pesan tampil)
    header("Location: login.php");
    exit;
}
// -----------------------------------------------------

// --- FUNGSI BANTUAN: KIRIM NOTIFIKASI ---
function fh_send_notification($mysqli, $userId, $title, $message, $type='info') {
    $stmt = $mysqli->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $userId, $title, $message, $type);
    $stmt->execute();
    $stmt->close();
}


// --- AUTOMATIC TRIGGERS (Saat Halaman Dibuka) ---

// 1. Cek Selamat Datang (Jika User Baru belum punya notifikasi sama sekali)
$checkWelcome = $mysqli->query("SELECT id FROM notifications WHERE user_id = $myUserId LIMIT 1");
if ($checkWelcome->num_rows == 0) {
    fh_send_notification($mysqli, $myUserId, "Selamat Datang! 👋", "Selamat datang di FamilyHood! Mulailah dengan menambahkan diri Anda atau orang tua Anda di menu 'Tambah'.", "info");
}

// 2. Cek Ulang Tahun Keluarga (Hari Ini)
// Hanya cek jika user membuka halaman Dashboard/Home
if ($action === 'home') {
    $todayMD = date('m-d');
    $yearNow = date('Y');
    
    // Cari anggota keluarga (milik user ini) yang ulang tahun hari ini
    $sqlBday = "SELECT id, name, date_of_birth FROM persons WHERE user_id = $myUserId AND DATE_FORMAT(date_of_birth, '%m-%d') = '$todayMD'";
    $resBday = $mysqli->query($sqlBday);
    
    while ($p = $resBday->fetch_assoc()) {
        // Cek apakah sudah dikirimi notif tahun ini? (Supaya tidak spam tiap refresh)
        $msgTitle = "Selamat Ulang Tahun, " . $p['name'] . "! 🎂";
        $checkSent = $mysqli->query("SELECT id FROM notifications WHERE user_id = $myUserId AND title = '$msgTitle' AND YEAR(created_at) = '$yearNow'");
        
        if ($checkSent->num_rows == 0) {
            // Hitung Umur
            $dob = new DateTime($p['date_of_birth']);
            $today = new DateTime();
            $age = $today->diff($dob)->y;
            
            fh_send_notification($mysqli, $myUserId, $msgTitle, "Hari ini " . $p['name'] . " berulang tahun yang ke-$age. Jangan lupa ucapkan selamat!", "birthday");
        }
    }
}

// --- LOGIKA "SIAPA MELIHAT SIAPA" ---
// Default: Kita melihat data kita sendiri
// --- LOGIKA TARGET USER (UPDATED) ---
// Default: Gunakan ID sendiri, KECUALI sedang membuka tree orang lain (sebagai kolaborator)
$targetUserId = $myUserId;

if (isset($_SESSION['current_tree_owner_id']) && isset($_SESSION['current_tree_id'])) {
    // Jika sedang dalam mode Tree aktif, semua operasi CRUD mengarah ke Pemilik Asli
    $targetUserId = $_SESSION['current_tree_owner_id'];
}
$isViewingOthers = false;

// Khusus Admin: Bisa melihat data orang lain jika diizinkan
if ($isAdmin && isset($_GET['view_user_id']) && isset($_GET['view_tree_id'])) {
    $requestedId   = intval($_GET['view_user_id']);
    $requestedTree = intval($_GET['view_tree_id']);
    
    if ($requestedId !== $myUserId) {
        // Cek 1: Apakah user target ini mengizinkan admin melihat tree ini?
        $check = $mysqli->query("SELECT t.user_id, t.name AS tree_name, u.name AS user_name 
                                FROM family_trees t 
                                JOIN users u ON u.id = t.user_id
                                WHERE t.id=$requestedTree 
                                AND t.user_id=$requestedId 
                                AND t.allow_admin_view = 1")->fetch_assoc();
        
        // Jika diizinkan:
        if ($check) {
            $targetUserId = $requestedId;
            $isViewingOthers = true;
            $targetUserName = $check['user_name'];
            
            // Simpan konteks tree yang sedang diintip ke SESSION ADMIN
            $_SESSION['admin_viewing_tree_id']   = $requestedTree;
            $_SESSION['admin_viewing_tree_name'] = $check['tree_name'];
            
        } else {
            die("Akses Ditolak: User ini tidak mengizinkan Admin melihat pohon keluarga ($requestedTree).");
        }
    }
}

// --- HELPER FUNCTIONS ---

function fh_render_single_web_card($p, $currentActiveId) {
    $photoUrl = 'assets/l.png';
    if (($p['gender']??'') === 'P') $photoUrl = 'assets/p.png';
    if (!empty($p['photo']) && file_exists(__DIR__ . '/' . $p['photo'])) {
        $photoUrl = $p['photo'];
    }
    
    $focusedClass = ($p['id'] == $currentActiveId) ? 'node-focused' : '';
    $deceasedClass = (($p['is_alive'] ?? 1) == 0) ? 'node-deceased' : '';
    
    echo '<div class="tree-node-web '.$deceasedClass.'">';
    echo '  <div class="node-card-content '.$focusedClass.'">';
    
    // --- 1. TOMBOL EDIT (KIRI ATAS) ---
    // Mengarah langsung ke halaman edit profil
    echo '      <a href="?action=bio&id='.$p['id'].'&mode=edit" class="tree-action-btn btn-tree-edit" title="Edit Profil">✎</a>';

    // --- 2. TOMBOL TAMBAH RELASI (KANAN ATAS) ---
    // Membuka Modal via Javascript
    // Kita kirim ID dan Nama orang tersebut ke fungsi JS
    $safeName = htmlspecialchars(addslashes($p['name']), ENT_QUOTES);
    echo '      <a href="javascript:void(0);" onclick="event.preventDefault(); event.stopPropagation(); openRelationModal('.$p['id'].', \''.$safeName.'\'); return false;" class="tree-action-btn btn-tree-add" title="Tambah Relasi">+</a>';
    
    // LINK FOTO (Untuk Fokus Tree)
    echo '      <a href="?action=tree&focus_id='.$p['id'].'" title="Lihat Relasi Keluarga">';
    echo '          <img src="'.$photoUrl.'" class="web-photo">';
    echo '      </a>';
    
    // LINK NAMA (Untuk Buka Biodata)
    $nameDisplay = htmlspecialchars($p['name']);
    if ((($p['is_alive'] ?? 1) == 0)) {
        $nameDisplay = 'alm. ' . $nameDisplay;
    }
    echo '      <a href="?action=bio&id='.$p['id'].'&mode=view" class="web-name" style="text-decoration:none; color:inherit;">';
    echo            $nameDisplay;
    echo '      </a>';
    
    echo '  </div>';
    echo '</div>';
}

function fh_get_persons_by_tree($mysqli, $treeId) {
    $rows = [];
    if (!$mysqli) return $rows;
    // Filter berdasarkan tree_id
    $sql = "SELECT id, name, place_of_birth, date_of_birth, gender, is_alive, note, created_at, updated_at, photo 
            FROM persons 
            WHERE tree_id = $treeId 
            ORDER BY name ASC";
    if ($result = $mysqli->query($sql)) {
        while ($row = $result->fetch_assoc()) { $rows[] = $row; }
        $result->free();
    }
    return $rows;
}

// --- LOGIKA GENERASI ---
// GANTI FUNGSI INI SEPENUHNYA:
function fh_compute_generations($mysqli, $treeId) {
    $persons = []; 
    $childParents = []; $parentChildren = []; 
    $spouses = []; $parents = []; $children = [];

    if (!$mysqli) return [[], 0, 0, [], [], [], []];

    // 1. Ambil Data Orang di Tree Ini
    $sqlPersons = "SELECT id, name, date_of_birth, gender, photo, is_alive, child_order FROM persons WHERE tree_id = $treeId ORDER BY id ASC";
    if ($res = $mysqli->query($sqlPersons)) {
        while ($row = $res->fetch_assoc()) {
            $id = (int)$row['id'];
            $persons[$id] = [
                'id' => $id, 
                'name' => $row['name'], 
                'dob' => $row['date_of_birth'] ?? null,
                'gender' => $row['gender'], 
                'photo' => $row['photo'],
                'is_alive' => $row['is_alive'] ?? 1,
                'child_order' => $row['child_order'] ?? null
            ];
        }
        $res->free();
    }
    
    // Jika tidak ada orang, kembalikan kosong
    if (empty($persons)) return [[], 0, 0, [], [], [], []];

    // 2. Kumpulkan ID orang untuk filter relasi
    $personIds = array_keys($persons);
    $idsStr = implode(',', $personIds); // Contoh hasil: "10,11,12"

    // 3. Ambil Relasi Orang Tua (Filter by Person ID, BUKAN User ID)
    // Gunakan "WHERE person_id IN (...)"
    $sqlRelParent = "SELECT person_id, related_person_id, relation_type FROM relations 
                     WHERE person_id IN ($idsStr) AND relation_type IN ('ayah','ibu')";
    
    if ($res = $mysqli->query($sqlRelParent)) {
        while ($row = $res->fetch_assoc()) {
            $child = (int)$row['person_id']; 
            $parent = (int)$row['related_person_id'];
            
            // Validasi: Pastikan kedua orang ada di tree ini
            if (isset($persons[$child]) && isset($persons[$parent])) {
                if (!isset($childParents[$child])) $childParents[$child] = [];
                $childParents[$child][$parent] = true;
                
                if (!isset($parentChildren[$parent])) $parentChildren[$parent] = [];
                $parentChildren[$parent][$child] = true;
                
                $parents[$parent] = true; 
                $children[$child] = true;
            }
        }
        $res->free();
    }

    // 4. Ambil Relasi Pasangan (Filter by Person ID)
    $sqlRelSpouse = "SELECT person_id, related_person_id, spouse_order FROM relations 
                     WHERE person_id IN ($idsStr) AND relation_type = 'pasangan'";
                     
    if ($res = $mysqli->query($sqlRelSpouse)) {
        while ($row = $res->fetch_assoc()) {
            $a = (int)$row['person_id']; 
            $b = (int)$row['related_person_id'];
            $order = (int)($row['spouse_order'] ?? 0);
            
            if (isset($persons[$a]) && isset($persons[$b])) {
                if (!isset($spouses[$a])) $spouses[$a] = [];
                if (!isset($spouses[$b])) $spouses[$b] = [];
                $spouses[$a][$b] = ['order' => $order];
                $spouses[$b][$a] = ['order' => $order]; // Simpan juga kebalikannya
            }
        }
        $res->free();
    }
                $spouses[$a][$b] = true; 
                $spouses[$b][$a] = true;
            }
        }
        $res->free();
    }

    // --- LOGIKA PERHITUNGAN GENERASI (TIDAK BERUBAH DARI SINI KE BAWAH) ---
    
    // Cari Root
    $rootPersons = [];
    foreach ($persons as $pid => $p) {
        $isParent = !empty($parents[$pid]);
        $isChild  = !empty($children[$pid]);
        if ($isParent && !$isChild) {
            $rootPersons[] = $pid;
        }
    }
    if (empty($rootPersons)) {
        foreach ($persons as $pid => $p) {
            if (empty($childParents[$pid])) $rootPersons[] = $pid;
        }
    }

    $generation = []; $queue = [];
    foreach ($rootPersons as $rid) {
        if (!isset($generation[$rid])) { $generation[$rid] = 1; $queue[] = $rid; }
    }

    for ($i = 0; $i < count($queue); $i++) {
        $person = $queue[$i]; $g = $generation[$person];
        if (!empty($spouses[$person])) {
            foreach ($spouses[$person] as $spouseId => $_) {
                if (!isset($generation[$spouseId])) { $generation[$spouseId] = $g; $queue[] = $spouseId; }
            }
        }
        if (!empty($parentChildren[$person])) {
            foreach ($parentChildren[$person] as $childId => $_) {
                $newGen = $g + 1;
                if (!isset($generation[$childId]) || $generation[$childId] < $newGen) {
                    $generation[$childId] = $newGen; $queue[] = $childId;
                }
            }
        }
    }

    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($spouses as $a => $list) {
            foreach ($list as $b => $_) {
                if (!isset($generation[$a]) || !isset($generation[$b])) continue;
                $maxG = max($generation[$a], $generation[$b]);
                if ($generation[$a] < $maxG) { $generation[$a] = $maxG; $changed = true; }
                if ($generation[$b] < $maxG) { $generation[$b] = $maxG; $changed = true; }
            }
        }
    }
    foreach ($persons as $pid => $_) { if (!isset($generation[$pid])) $generation[$pid] = 1; }

    $personsByGen = []; $maxGen = 0;
    foreach ($generation as $pid => $g) {
        if ($g < 1) $g = 1;
        if (!isset($personsByGen[$g])) $personsByGen[$g] = [];
        $personsByGen[$g][] = $persons[$pid]['name'];
        if ($g > $maxGen) $maxGen = $g;
    }
    
    return [$personsByGen, $maxGen, 0, $generation, $persons, $parentChildren, $spouses];
}

// --- FUNGSI PENGGABUNG KELUARGA (UNION-FIND) ---
function fh_group_anchors($anchors, $persons, $spouses, $parentChildren) {
    $parent = [];
    foreach (array_keys($persons) as $id) $parent[$id] = $id;
    
    $find = function($i) use (&$parent) {
        $path = [];
        while ($parent[$i] != $i) { $path[] = $i; $i = $parent[$i]; }
        foreach ($path as $node) $parent[$node] = $i;
        return $i;
    };
    $union = function($i, $j) use (&$parent, $find) {
        $root_i = $find($i); $root_j = $find($j);
        if ($root_i != $root_j) $parent[$root_i] = $root_j;
    };
    
    foreach ($spouses as $a => $list) { foreach ($list as $b => $_) $union($a, $b); }
    foreach ($parentChildren as $p => $kids) { foreach ($kids as $k => $_) $union($p, $k); }
    
    $groups = [];
    foreach ($anchors as $a) {
        $root = $find($a);
        if (!isset($groups[$root])) $groups[$root] = [];
        $groups[$root][] = $a;
    }
    return array_values($groups);
}

// --- HITUNG TOTAL ANGGOTA DALAM CLUSTER (Helper Baru) ---
function fh_count_descendants($rootIds, $spouses, $parentChildren, &$visited) {
    $count = 0;
    $queue = $rootIds;
    
    while(!empty($queue)) {
        $currentId = array_shift($queue);
        if (isset($visited[$currentId])) continue;
        $visited[$currentId] = true;
        $count++;

        // Tambahkan Pasangan ke queue
        if (!empty($spouses[$currentId])) {
            foreach ($spouses[$currentId] as $sId => $_) {
                if (!isset($visited[$sId])) $queue[] = $sId;
            }
        }
        // Tambahkan Anak ke queue
        if (!empty($parentChildren[$currentId])) {
            foreach ($parentChildren[$currentId] as $cId => $_) {
                if (!isset($visited[$cId])) $queue[] = $cId;
            }
        }
    }
    return $count;
}

function fh_render_tree_web($personId, $persons, $spouses, $parentChildren, $currentActiveId) {
    if (!isset($persons[$personId])) return;
    $p = $persons[$personId];
    echo '<li>';
    echo '<div style="display:inline-flex; align-items:center;">';
    fh_render_single_web_card($p, $currentActiveId);
    if (!empty($spouses[$personId])) {
        foreach ($spouses[$personId] as $spouseId => $_) {
            if (isset($persons[$spouseId])) {
                echo '<div class="spouse-connector-web"></div>';
                fh_render_single_web_card($persons[$spouseId], $currentActiveId);
            }
        }
    }
    echo '</div>';
    
    $groupIds = [$personId];
    if (!empty($spouses[$personId])) {
        foreach ($spouses[$personId] as $sid => $_) { $groupIds[] = $sid; }
    }
    
    $childIdsMap = [];
    foreach ($groupIds as $pid) {
        if (!empty($parentChildren[$pid])) {
            foreach ($parentChildren[$pid] as $cid => $_) { $childIdsMap[$cid] = true; }
        }
    }
    $childIds = array_keys($childIdsMap);
    
    if (!empty($childIds)) {
        usort($childIds, function($a, $b) use ($persons) {
            $oa = $persons[$a]['child_order'] ?? null; $ob = $persons[$b]['child_order'] ?? null;
            if ($oa && $ob) return ($oa <=> $ob); // Sortir berdasarkan child_order
            elseif ($oa && !$ob) return -1; elseif (!$oa && $ob) return 1;
            
            $da = $persons[$a]['dob'] ?? null; $db = $persons[$b]['dob'] ?? null;
            $ka = ($da && $da !== '0000-00-00') ? $da : null; $kb = ($db && $db !== '0000-00-00') ? $db : null;
            if ($ka && $kb && $ka !== $kb) return strcmp($ka, $kb); elseif ($ka && !$kb) return -1; elseif (!$ka && $kb) return 1;
            return strnatcasecmp($persons[$a]['name'], $persons[$b]['name']);
        });
        echo '<ul>';
        foreach ($childIds as $childId) {
            fh_render_tree_web($childId, $persons, $spouses, $parentChildren, $currentActiveId);
        }
        echo '</ul>';
    }
    echo '</li>';
}

// --- DATA EXPORT HELPERS (Sama seperti sebelumnya) ---
function fh_get_group_label_for_person($id, $gen, $generation, $persons, $spouses) {
    if (!isset($persons[$id])) return '';
    $mainId = $id; $otherIds = [];
    if (!empty($spouses[$mainId])) {
        foreach ($spouses[$mainId] as $spouseId => $_) { if (isset($persons[$spouseId])) $otherIds[] = $spouseId; }
    }
    usort($otherIds, function($a, $b) use ($persons) {
        $da = $persons[$a]['dob'] ?? null; $db = $persons[$b]['dob'] ?? null;
        $ka = ($da && $da !== '0000-00-00') ? $da : null; $kb = ($db && $db !== '0000-00-00') ? $db : null;
        if ($ka && $kb) { if ($ka !== $kb) return strcmp($ka, $kb); } elseif ($ka && !$kb) return -1; elseif (!$ka && $kb) return 1;
        return strnatcasecmp($persons[$a]['name'], $persons[$b]['name']);
    });
    $groupIds = array_merge([$mainId], $otherIds);
    $names = [];
    foreach ($groupIds as $pid) $names[] = $persons[$pid]['name'];
    return implode(' & ', $names);
}

function fh_get_family_rows_recursive($personId, $currentGen, $persons, $spouses, $parentChildren, $generationData, $childNumber = null) {
    $label = fh_get_group_label_for_person($personId, $currentGen, $generationData, $persons, $spouses);
    if ($currentGen > 1 && $childNumber !== null) $label = $childNumber . '. ' . $label;

    $groupIds = [$personId];
    if (!empty($spouses[$personId])) {
        foreach ($spouses[$personId] as $sid => $_) $groupIds[] = $sid;
    }
    $childIdsMap = [];
    foreach ($groupIds as $pid) {
        if (!empty($parentChildren[$pid])) {
            foreach ($parentChildren[$pid] as $cid => $_) $childIdsMap[$cid] = true;
        }
    }
    $childIds = array_keys($childIdsMap);

    if (empty($childIds)) return [[ $currentGen => $label ]];

    usort($childIds, function($a, $b) use ($persons) {
        $oa = $persons[$a]['child_order'] ?? null; $ob = $persons[$b]['child_order'] ?? null;
        if ($oa && $ob) return ($oa <=> $ob);
        elseif ($oa && !$ob) return -1; elseif (!$oa && $ob) return 1;
        
        $da = $persons[$a]['dob'] ?? null; $db = $persons[$b]['dob'] ?? null;
        $ka = ($da && $da !== '0000-00-00') ? $da : null; $kb = ($db && $db !== '0000-00-00') ? $db : null;
        if ($ka && $kb && $ka !== $kb) return strcmp($ka, $kb); elseif ($ka && !$kb) return -1; elseif (!$ka && $kb) return 1;
        return strnatcasecmp($persons[$a]['name'], $persons[$b]['name']);
    });

    $allDescendantRows = [];
    $childGen = $currentGen + 1;
    foreach ($childIds as $index => $childId) {
        $nextNumber = $index + 1;
        $childRows = fh_get_family_rows_recursive($childId, $childGen, $persons, $spouses, $parentChildren, $generationData, $nextNumber);
        foreach ($childRows as $row) $allDescendantRows[] = $row;
    }
    if (isset($allDescendantRows[0])) $allDescendantRows[0][$currentGen] = $label;
    return $allDescendantRows;
}



// === EXPORT HANDLERS (UPDATED) ===
if (isset($_GET['export'])) {
    global $mysqli;
    
    // 1. Ambil Filter ID jika ada
    $filterRootId = isset($_GET['filter_root']) ? intval($_GET['filter_root']) : 0;

    // 2. Hitung Generasi (Sama seperti View)
    $activeTreeId = $_SESSION['current_tree_id'] ?? 0;
        
        // --- PERBAIKAN: POPUP ALERT JIKA BELUM PILIH ---

        
    list($personsByGen, $maxGen, $maxHeight, $generation, $persons, $parentChildren, $spouses) = fh_compute_generations($mysqli, $activeTreeId);

    // 3. Tentukan Siapa yang akan di-Export (Roots)
    $rootsToExport = [];

    if ($filterRootId > 0) {
        // KASUS 1: Export SATU Keluarga Saja
        // Validasi: pastikan ID ini ada
        if (isset($persons[$filterRootId])) {
            $rootsToExport[] = $filterRootId;
        }
    } else {
        // KASUS 2: Export SEMUA Keluarga (Sama seperti Tampilan Awal)
        // Cari semua Gen 1
        $allRoots = [];
        foreach ($persons as $pid => $info) {
            if (($generation[$pid] ?? 999) == 1) {
                $allRoots[] = $pid;
            }
        }
        // Urutkan Nama
        usort($allRoots, function($a, $b) use ($persons) { 
            return strnatcasecmp($persons[$a]['name'], $persons[$b]['name']); 
        });

        // Deduplikasi Pasangan (Agar suami istri tidak muncul 2x sebagai judul terpisah)
        $processed = [];
        foreach ($allRoots as $rid) {
            if (isset($processed[$rid])) continue;
            
            // Masukkan ke daftar export
            $rootsToExport[] = $rid;
            $processed[$rid] = true;

            // Tandai pasangannya agar tidak masuk lagi
            if (!empty($spouses[$rid])) {
                foreach($spouses[$rid] as $sid => $_) {
                    // Hanya tandai jika pasangan juga Gen 1
                    if (($generation[$sid]??0) == 1) $processed[$sid] = true;
                }
            }
        }
    }

   // --- LOGIC EXCEL & WORD (TABEL DATA) ---
    if ($_GET['export'] === 'excel' || $_GET['export'] === 'word') {
        
        // 1. BERSIH-BERSIH BUFFER DI AWAL (PENTING!)
        // Hapus semua output yang mungkin tidak sengaja tercetak sebelumnya (spasi, enter dari include, dll)
        if (ob_get_length()) ob_end_clean();
        
        // Matikan error display agar teks error tidak masuk ke dalam file download
        ini_set('display_errors', 0);
        error_reporting(0);
        
        // Matikan kompresi GZIP server (sering merusak file .docx/.xlsx)
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', 1);
        }
        @ini_set('zlib.output_compression', 'Off');
        
        $filename = "familyhood_" . ($filterRootId ? "bani_".$filterRootId : "all") . "_" . date('Ymd_His');

        // --- Query Data ---
        $finalRows = [];
        $realMaxGen = 0;
        foreach ($rootsToExport as $anchorId) {
            $familyRows = fh_get_family_rows_recursive($anchorId, 1, $persons, $spouses, $parentChildren, $generation, null);
            foreach ($familyRows as $fRow) {
                if (!empty($fRow)) {
                    $maxKey = max(array_keys($fRow));
                    if ($maxKey > $realMaxGen) $realMaxGen = $maxKey;
                }
                $finalRows[] = $fRow;
            }
            $finalRows[] = []; 
        }

        // Cek Library
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet') || !class_exists('\\PhpOffice\\PhpWord\\PhpWord')) {
            die('Error: Library PhpSpreadsheet / PhpWord tidak terdeteksi.');
        }

        // ==========================
        // OPSI 1: EXPORT EXCEL
        // ==========================
        if ($_GET['export'] === 'excel') {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            for ($g = 1; $g <= $realMaxGen; $g++) {
                $sheet->setCellValueByColumnAndRow($g, 1, 'Generasi '.$g);
                $sheet->getStyleByColumnAndRow($g, 1)->getFont()->setBold(true);
                $sheet->getStyleByColumnAndRow($g, 1)->getFill()
                      ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                      ->getStartColor()->setARGB('FFE5E7EB');
            }

            $rowIndex = 2;
            foreach ($finalRows as $row) {
                if (empty($row)) { $rowIndex++; continue; }
                for ($g = 1; $g <= $realMaxGen; $g++) {
                    $val = isset($row[$g]) ? (string)$row[$g] : '';
                    $sheet->setCellValueByColumnAndRow($g, $rowIndex, $val);
                }
                $rowIndex++;
            }

            for ($g = 1; $g <= $realMaxGen; $g++) {
                $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($g);
                $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
            }

            // Header Download
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');
            header('Cache-Control: max-age=0');
            
            // Bersihkan buffer lagi tepat sebelum save
            if (ob_get_length()) ob_end_clean();

            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
            exit;
        } 
        
        // ==========================
        // OPSI 2: EXPORT WORD (FIXED)
        // ==========================
        else {
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            
            // Set Metadata File
            $docInfo = $phpWord->getDocInfo();
            $docInfo->setTitle("Laporan Silsilah");
            $docInfo->setSubject("Family Tree");

            // Atur Halaman Landscape
            $section = $phpWord->addSection([
                'orientation' => 'landscape',
                'marginTop' => 600, 'marginLeft' => 600, 'marginRight' => 600, 'marginBottom' => 600
            ]);

            // Judul Dokumen
            $titleText = "Laporan Silsilah Keluarga";
            if ($filterRootId > 0 && isset($persons[$filterRootId])) {
                $titleText .= " Bani " . $persons[$filterRootId]['name'];
            }
            $section->addText($titleText, ['bold'=>true, 'size'=>16], ['align'=>'center']);
            $section->addTextBreak(1);

            // Style Tabel
            $styleTable = ['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80];
            $phpWord->addTableStyle('FamilyTreeTable', $styleTable);
            $table = $section->addTable('FamilyTreeTable');

            // Header Tabel
            $table->addRow();
            $cellWidth = 2000; 
            for ($g = 1; $g <= $realMaxGen; $g++) {
                $table->addCell($cellWidth, ['bgColor' => 'E5E7EB'])->addText("Generasi $g", ['bold'=>true]);
            }

            // Isi Data
            foreach ($finalRows as $row) {
                // Baris Kosong (Pemisah)
                if (empty($row)) {
                    $table->addRow();
                    for ($g = 1; $g <= $realMaxGen; $g++) {
                        $table->addCell($cellWidth, ['borderTopSize'=>0, 'borderBottomSize'=>0, 'borderLeftSize'=>0, 'borderRightSize'=>0]); 
                    }
                    continue;
                }

                $table->addRow();
                for ($g = 1; $g <= $realMaxGen; $g++) {
                    $text = isset($row[$g]) ? (string)$row[$g] : '';
                    
                    // --- SANITASI TEKS (Sangat Penting untuk Word) ---
                    // Hapus karakter kontrol (seperti NULL bytes) yang bisa merusak XML Word
                    $cleanText = preg_replace('/[\x00-\x1F\x7F]/', '', $text); 
                    $cleanText = htmlspecialchars($cleanText); 
                    
                    $table->addCell($cellWidth)->addText($cleanText);
                }
            }

            // --- HEADER DOWNLOAD WORD ---
            header("Content-Description: File Transfer");
            header('Content-Disposition: attachment; filename="'.$filename.'.docx"');
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Expires: 0');
            
            // BERSIHKAN BUFFER LAGI (Final Check)
            // Ini memastikan tidak ada 1 byte pun sampah yang ikut terkirim
            while (ob_get_level()) { ob_end_clean(); }

            // Tulis file
            $xmlWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            $xmlWriter->save("php://output");
            exit;
        }
    }

    // --- LOGIC PDF ---
    if ($_GET['export'] === 'pdf') {
        // Helper PDF (Internal function, copy dari sebelumnya tapi dirapikan)
        if (!function_exists('fh_render_single_card_pdf')) {
            function fh_render_single_card_pdf($p) {
                 $userPhoto = $p['photo'] ?? '';
                 $fullUserPath = __DIR__ . '/' . $userPhoto;
                 $gender = strtoupper($p['gender'] ?? '');
                 $imagePathToUse = '';
                 if (!empty($userPhoto) && file_exists($fullUserPath)) $imagePathToUse = $fullUserPath;
                 else {
                     $assetFile = ($gender === 'P') ? 'assets/p.png' : 'assets/l.png';
                     $fullAssetPath = __DIR__ . '/' . $assetFile;
                     if (file_exists($fullAssetPath)) $imagePathToUse = $fullAssetPath;
                 }
                 $avatarHtml = '';
                 if ($imagePathToUse) {
                     $ext = pathinfo($imagePathToUse, PATHINFO_EXTENSION) ?: 'png';
                     $fileContent = @file_get_contents($imagePathToUse);
                     if ($fileContent) {
                         $imgData = base64_encode($fileContent);
                         $src = 'data:image/' . $ext . ';base64,' . $imgData;
                         $avatarHtml = '<img src="'.$src.'">';
                     }
                 }
                 if (empty($avatarHtml)) {
                     $color = ($gender === 'P') ? '#ec4899' : '#3b82f6';
                     $txt   = ($gender ?: '?');
                     $avatarHtml = '<div style="background:'.$color.'; width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:bold; font-size:1.2rem;">'.$txt.'</div>';
                 }
                 echo '<div class="node-card"><div class="node-circle">' . $avatarHtml . '</div><div class="node-name">' . htmlspecialchars($p['name']) . '</div></div>';
            }
        }

        if (!function_exists('fh_pdf_recurse')) {
            function fh_pdf_recurse($personId, $persons, $spouses, $parentChildren) {
                if (!isset($persons[$personId])) return;
                echo '<li><div class="tree-content">';
                fh_render_single_card_pdf($persons[$personId]);
                if (!empty($spouses[$personId])) {
                    foreach ($spouses[$personId] as $spouseId => $_) {
                        echo '<div class="spouse-connector">❤</div>';
                        if (isset($persons[$spouseId])) fh_render_single_card_pdf($persons[$spouseId]);
                    }
                }
                echo '</div>';
                
                $groupIds = [$personId];
                if (!empty($spouses[$personId])) foreach ($spouses[$personId] as $sid => $_) $groupIds[] = $sid;
                
                $childIdsMap = [];
                foreach ($groupIds as $pid) {
                    if (!empty($parentChildren[$pid])) foreach ($parentChildren[$pid] as $cid => $_) $childIdsMap[$cid] = true;
                }
                $childIds = array_keys($childIdsMap);

                if (!empty($childIds)) {
                    usort($childIds, function($a, $b) use ($persons) {
                        $oa = $persons[$a]['child_order'] ?? null; $ob = $persons[$b]['child_order'] ?? null;
                        if ($oa && $ob) return ($oa <=> $ob);
                        elseif ($oa && !$ob) return -1; elseif (!$oa && $ob) return 1;
                        
                        $da = $persons[$a]['dob'] ?? null; $db = $persons[$b]['dob'] ?? null;
                        $ka = ($da && $da !== '0000-00-00') ? $da : null; $kb = ($db && $db !== '0000-00-00') ? $db : null;
                        if ($ka && $kb && $ka !== $kb) return strcmp($ka, $kb);
                        elseif ($ka && !$kb) return -1; elseif (!$ka && $kb) return 1;
                        return strnatcasecmp($persons[$a]['name'], $persons[$b]['name']);
                    });
                    echo '<ul>';
                    foreach ($childIds as $childId) fh_pdf_recurse($childId, $persons, $spouses, $parentChildren);
                    echo '</ul>';
                }
                echo '</li>';
            }
        }

        echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Pohon Keluarga PDF</title>';
        echo '<style>
        /* Tambahkan ini di dalam <style> di bagian atas */
        @keyframes popUp { 
            from { transform: scale(0.8); opacity: 0; } 
            to { transform: scale(1); opacity: 1; } 
        }
        * { box-sizing: border-box; } body { margin:0; font-family: sans-serif; }
        .tree { display: table; margin: 0 auto; }
        .tree ul { padding-top: 20px; position: relative; transition: all 0.5s; display: flex; justify-content: center; }
        .tree li { float: left; text-align: center; list-style-type: none; position: relative; padding: 20px 5px 0 5px; transition: all 0.5s; }
        .tree li::before, .tree li::after { content: ""; position: absolute; top: 0; right: 50%; border-top: 1px solid #ccc; width: 50%; height: 20px; }
        .tree li::after { right: auto; left: 50%; border-left: 1px solid #ccc; }
        .tree li:only-child::after, .tree li:only-child::before { display: none; }
        .tree li:only-child { padding-top: 0; }
        .tree li:first-child::before, .tree li:last-child::after { border: 0 none; }
        .tree li:last-child::before { border-right: 1px solid #ccc; border-radius: 0 5px 0 0; }
        .tree li:first-child::after { border-radius: 5px 0 0 0; }
        .tree ul ul::before { content: ""; position: absolute; top: 0; left: 50%; border-left: 1px solid #ccc; width: 0; height: 20px; }
        .tree-content { display: flex; align-items: center; justify-content: center; gap: 5px; }
        .node-card { display: inline-block; background: #fff; border: 1px solid #ccc; padding: 5px; border-radius: 5px; min-width: 80px; }
        .node-circle { width: 40px; height: 40px; border-radius: 50%; overflow: hidden; margin: 0 auto 5px; background: #eee; }
        .node-circle img { width: 100%; height: 100%; object-fit: cover; }
        .node-name { font-size: 10px; font-weight: bold; }
        .spouse-connector { color: red; font-size: 10px; margin: 0 2px; }
        @media print { .no-print { display: none; } }
        </style></head><body onload="window.print()">';
        echo '<a href="#" onclick="window.print(); return false;" class="no-print">🖨️ Cetak PDF</a>';
        
        $title = "Diagram Keluarga Besar";
        if ($filterRootId > 0 && isset($persons[$filterRootId])) {
            $title .= " Bani " . htmlspecialchars($persons[$filterRootId]['name']);
        }
        echo '<h2 style="text-align:center;">'.$title.'</h2>';
        
        // Loop Roots yang sudah difilter
        foreach ($rootsToExport as $rootId) {
             echo '<div class="tree"><ul>';
             fh_pdf_recurse($rootId, $persons, $spouses, $parentChildren);
             echo '</ul></div><hr style="margin:40px 0; border:0; border-top:1px dashed #ccc;">';
        }
        
        echo '</body></html>';
        exit;
    }
}

function redirect($url) { header('Location: ' . $url); exit; }

function handle_photo_upload($fieldName, $oldPath = null) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) return $oldPath;
    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    $ext = pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $newName = 'person_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $target  = $uploadDir . '/' . $newName;
    if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], $target)) {
        if ($oldPath && file_exists(__DIR__ . '/' . ltrim($oldPath, '/'))) @unlink(__DIR__ . '/' . ltrim($oldPath, '/'));
        return 'uploads/' . $newName;
    }
    return $oldPath;
}

function label_gender($gender) {
    $gender = strtoupper($gender ?? '');
    return ($gender === 'L') ? 'Laki-laki' : (($gender === 'P') ? 'Perempuan' : 'Tidak diketahui');
}

function label_alive($v) {
    if ($v === null || $v === '') return 'Tidak diketahui';
    return $v ? 'Hidup' : 'Wafat';
}

function render_avatar_svg($gender) {
    $gender = strtoupper($gender ?? '');
    $imgSrc = ($gender === 'P') ? 'assets/p.png' : 'assets/l.png';
    return '<img src="' . $imgSrc . '" alt="' . $gender . '" style="width:100%; height:100%; object-fit:cover; display:block;">';
}

$action = $_GET['action'] ?? 'home';
$currentPerson = null;
$relations = [];
$parents = $children = $siblings = $spouses = [];
$otherPersons = [];
$allPersons = [];
$error = ''; $success = ''; $bio_error = ''; $bio_success = '';

// --- LOGIKA TAMBAH / EDIT / HAPUS DATA (CRUD) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Pengamanan: Admin hanya boleh lihat-lihat (View Only) jika sedang mode intip
    if ($isViewingOthers) {
        die("MODE VIEW ONLY: Anda sedang melihat data user lain. Tidak diizinkan mengubah data.");
    }

    // 1. TAMBAH ORANG (QUICK)
    if (isset($_POST['create_person_quick'])) {
        $name = trim($_POST['name']??''); 
        $gender = trim($_POST['gender']??''); 
        $note = trim($_POST['note']??'');
        
        if ($name === '') { $error = "Nama kosong."; }
        else {
            // Cek dulu jumlah keluarga saat ini (Untuk deteksi Keluarga Baru vs Tambah Anggota)
            $countCheck = $mysqli->query("SELECT COUNT(*) as total FROM persons WHERE user_id = $targetUserId")->fetch_assoc()['total'];
            $myName = $_SESSION['user_name']; // Ambil nama user yang login

            // INSERT dengan last_editor_name
            $stmt = $mysqli->prepare("INSERT INTO persons (user_id, tree_id, name, gender, place_of_birth, date_of_birth, is_alive, note, last_editor_name) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("iisssssss", $targetUserId, $treeId, $name, $gender, $place_of_birth, $dob, $alive, $note, $myName);
            // ...

            
            if ($stmt->execute()) {
                $success = "Anggota dibuat."; 
                
                // --- LOGIKA NOTIFIKASI TAMBAH ---
                if ($countCheck == 0) {
                    // Ini adalah anggota PERTAMA (Keluarga Baru)
                    fh_send_notification($mysqli, $targetUserId, "Keluarga Baru Terbentuk! 🌱", "Selamat! Anda telah memulai pohon keluarga baru dengan menambahkan $name.", "success");
                } else {
                    // Ini adalah penambahan anggota selanjutnya
                    fh_send_notification($mysqli, $targetUserId, "Anggota Baru Ditambahkan 👶", "$name berhasilat ditambahkan ke dalam silsilah keluarga.", "success");
                }
                // --------------------------------
                
            } else $error = $stmt->error;
            $stmt->close();
        }
    }
    
    // --- 5. LOGIKA TOGGLE STATUS USER (Admin Only) ---
    elseif (isset($_POST['toggle_user_status']) && $isAdmin) {
        $userIdToToggle = intval($_POST['toggle_user_id']);
        $newStatus = intval($_POST['new_status']);
        
        // Jangan biarkan admin menonaktifkan dirinya sendiri
        if ($userIdToToggle == $myUserId) {
             $error = "Tidak bisa menonaktifkan akun Anda sendiri.";
        } else {
             $stmt = $mysqli->prepare("UPDATE users SET is_active=? WHERE id=? AND role='user'");
             $stmt->bind_param("ii", $newStatus, $userIdToToggle);
             if ($stmt->execute()) {
                 $statusText = ($newStatus == 1) ? 'aktifkan' : 'nonaktifkan';
                 $success = "User berhasil di$statusText.";
             } else {
                 $error = "Gagal mengubah status user.";
             }
             $stmt->close();
        }
        $action = 'admin_users'; // Kembali ke halaman admin
    }
    
    // --- 6. LOGIKA TOGGLE ADMIN VIEW PER TREE (User Side) ---
    elseif (isset($_POST['toggle_tree_admin_view'])) {
        $treeId = intval($_POST['tree_id']);
        $newStatus = intval($_POST['new_status']);
        
        // Pastikan TreeID milik user yang sedang login
        $checkTree = $mysqli->prepare("SELECT id FROM family_trees WHERE id = ? AND user_id = ?");
        $checkTree->bind_param("ii", $treeId, $myUserId);
        $checkTree->execute();
        $checkTree->store_result();
        
        if ($checkTree->num_rows > 0) {
            $stmt = $mysqli->prepare("UPDATE family_trees SET allow_admin_view=? WHERE id=?");
            $stmt->bind_param("ii", $newStatus, $treeId);
            if ($stmt->execute()) {
                $statusText = ($newStatus == 1) ? 'diizinkan' : 'dikunci';
                $success = "Akses Admin untuk Keluarga berhasil di$statusText.";
            } else {
                $error = "Gagal mengubah status akses Admin.";
            }
            $stmt->close();
        } else {
            $error = "Tree ID tidak valid atau bukan milik Anda.";
        }
        $checkTree->close();
        $action = 'settings'; // Kembali ke halaman settings
    }
    
    // --- 7. LOGIKA PERMINTAAN KIRIM EMAIL AKTIVASI ULANG ---
    elseif (isset($_POST['request_reactivation'])) {
        // Logika ini harusnya ada di login.php, tapi karena form di login.php post ke index.php, kita handle di sini.
        
        $userEmail = trim($_POST['email']);
        
        // Cari user nonaktif dengan email tersebut
        $stmt = $mysqli->prepare("SELECT id, name, is_active FROM users WHERE email = ?");
        $stmt->bind_param("s", $userEmail);
        $stmt->execute();
        $result = $stmt->get_result();
        $u = $result->fetch_assoc();
        $stmt->close();
        
        if ($u && $u['is_active'] == 0) {
            
            // Generate kode aktivasi baru
            $newCode = md5(time() . $u['id'] . $u['name']);
            
            // Simpan kode aktivasi baru ke database
            $stmt = $mysqli->prepare("UPDATE users SET activation_code = ? WHERE id = ?");
            $stmt->bind_param("si", $newCode, $u['id']);
            $stmt->execute();
            $stmt->close();

            // Asumsi URL Aplikasi Anda adalah http://yourdomain.com/
            // GANTI DENGAN DOMAIN ASLI ANDA
            $activationLink = "http://familyhood.my.id/index.php?action=activate&code=" . $newCode;
            
            // --- KIRIM EMAIL (Simulasi) ---
            $subject = "Konfirmasi Ulang Akun FamilyHood Anda";
            $message = "Halo " . htmlspecialchars($u['name']) . ",\n\nAkun Anda memerlukan konfirmasi ulang (re-aktivasi). Klik tautan di bawah ini untuk mengaktifkan akun Anda kembali dan bisa login:\n\n" . $activationLink . "\n\nJika Anda tidak merasa mengajukan permintaan ini, abaikan email ini.\n\nSalam,\nFamilyHood Team";
            $headers = "From: no-reply@familyhood.com";
            
            // *Uncomment baris di bawah ini jika server Anda mendukung fungsi mail() PHP*
            // @mail($userEmail, $subject, $message, $headers); 
            
            $_SESSION['status_success'] = "Email konfirmasi telah dikirim ke **" . htmlspecialchars($userEmail) . "**. Silakan cek kotak masuk dan folder spam Anda, atau hubungi Admin jika Anda tidak menerimanya.";
            
        } elseif ($u && $u['is_active'] == 1) {
             $_SESSION['status_error'] = "Akun Anda sudah aktif. Silakan coba login langsung.";
        } else {
             $_SESSION['status_error'] = "Email tidak terdaftar.";
        }

        // Redirect ke login.php untuk menampilkan pesan (Success/Error)
        header("Location: login.php");
        exit;
    }
    
    
    // 2. TAMBAH ORANG (LENGKAP)
    elseif (isset($_POST['create_person_full'])) {
        
        
        
        $name = trim($_POST['name']??''); 
        $gender = trim($_POST['gender']??''); 
        $place_of_birth = trim($_POST['place_of_birth']??'');
        $dob = empty($_POST['date_of_birth']) ? null : $_POST['date_of_birth'];
        $alive = ($_POST['is_alive'] === '') ? 1 : (int)$_POST['is_alive'];
        $note = trim($_POST['note']??'');
        $child_order = (isset($_POST['child_order']) && intval($_POST['child_order']) > 0) ? intval($_POST['child_order']) : null;
        
        $from_id = intval($_POST['from_id']??0);
        $from_rel = $_POST['from_relation_type']??'';

        // Auto-detect gender jika relasi tertentu
        if ($from_rel === 'ibu') $gender = 'P'; 
        elseif ($from_rel === 'ayah') $gender = 'L';
        elseif ($from_rel === 'pasangan' && $from_id > 0) {
            // Cek gender pasangan
            $r = $mysqli->query("SELECT gender FROM persons WHERE id=$from_id AND user_id=$targetUserId")->fetch_assoc();
            if ($r) $gender = ($r['gender']=='L') ? 'P' : 'L';
        }

        if ($name === '') { 
            $error = "Nama tidak boleh kosong."; 
            $action = 'add_person'; 
        } elseif ($gender === '') { 
            $error = "Peringatan: Jenis kelamin harus dipilih."; 
            $action = 'add_person'; 
        } else {
            // Validasi input sukses, sekarang cek Tree ID
            $treeId = $_SESSION['current_tree_id'] ?? 0;
            
            if ($treeId == 0) {
                $error = "Session Error: Tidak ada proyek keluarga yang dipilih. Silakan kembali ke Home.";
            } else {
                $myName = $_SESSION['user_name']; // Ambil nama user yang login
                // INSERT dengan user_id (Logika simpan data masuk di sini)
                $stmt = $mysqli->prepare("INSERT INTO persons (user_id, tree_id, name, gender, place_of_birth, date_of_birth, is_alive, note, child_order, last_editor_name) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("iissssissi", $targetUserId, $treeId, $name, $gender, $place_of_birth, $dob, $alive, $note, $child_order, $myName);
                
                if ($stmt->execute()) {
                    $newId = $stmt->insert_id;
                    $stmt->close();
                    
                    // LOGIKA RELASI OTOMATIS
                    if ($from_id > 0 && $from_rel !== '') {
                        $pid = $from_id; $rid = $newId; $rtype = $from_rel;
                        
                        // Insert Relasi (Pakai user_id)
                        $mysqli->query("INSERT INTO relations (user_id, person_id, related_person_id, relation_type) VALUES ($targetUserId, $pid, $rid, '$rtype')");
                        
                        // Insert Relasi Kebalikannya
                        $revType = null;
                        if ($rtype == 'ayah' || $rtype == 'ibu') $revType = 'anak';
                        elseif ($rtype == 'anak') {
                            $pg = $mysqli->query("SELECT gender FROM persons WHERE id=$pid")->fetch_assoc()['gender']??'';
                            $revType = (strtoupper($pg)=='P') ? 'ibu' : 'ayah';
                        } elseif ($rtype == 'saudara') $revType = 'saudara';
                        elseif ($rtype == 'pasangan') $revType = 'pasangan';
                        
                        if ($revType) {
                            $mysqli->query("INSERT INTO relations (user_id, person_id, related_person_id, relation_type) VALUES ($targetUserId, $rid, $pid, '$revType')");
                        }

                        // Logic saudara satu orang tua
                        if ($rtype === 'saudara' && !empty($_POST['same_parents'])) {
                             $resP = $mysqli->query("SELECT related_person_id, relation_type FROM relations WHERE person_id=$pid AND relation_type IN ('ayah','ibu') AND user_id=$targetUserId");
                             while($pd = $resP->fetch_assoc()) {
                                 $parId = $pd['related_person_id']; $role = $pd['relation_type'];
                                 $mysqli->query("INSERT IGNORE INTO relations (user_id, person_id, related_person_id, relation_type) VALUES ($targetUserId, $newId, $parId, '$role')");
                                 $mysqli->query("INSERT IGNORE INTO relations (user_id, person_id, related_person_id, relation_type) VALUES ($targetUserId, $parId, $newId, 'anak')");
                             }
                        }
                    }
                    redirect("?action=bio&id=$newId&mode=edit");
                } else {
                    $error = "Gagal menyimpan: " . $stmt->error; 
                    $action = 'add_person';
                }
            }
        }
        }
    
    // 3. UPDATE BIODATA
    elseif ($action === 'update_bio') {
        $id = intval($_POST['id']??0);
        if ($id > 0) {
            // Pastikan hanya mengupdate data milik sendiri (AND user_id=...)
            $pOld = $mysqli->query("SELECT * FROM persons WHERE id=$id AND user_id=$targetUserId")->fetch_assoc();
            
            if ($pOld) {
                $name = trim($_POST['name']??''); 
                $gender = trim($_POST['gender']??'');
                $pob = trim($_POST['place_of_birth']??'');
                $dob = empty($_POST['date_of_birth']) ? null : $_POST['date_of_birth'];
                $alive = ($_POST['is_alive'] === '') ? 1 : (int)$_POST['is_alive'];
                $note = trim($_POST['note']??'');
                $child_order = (isset($_POST['child_order']) && intval($_POST['child_order']) > 0) ? intval($_POST['child_order']) : null;
                $photoPath = handle_photo_upload('photo', $pOld['photo']);
                $myName = $_SESSION['user_name'];

                $stmt = $mysqli->prepare("UPDATE persons SET name=?, gender=?, place_of_birth=?, date_of_birth=?, is_alive=?, note=?, photo=?, child_order=?, last_editor_name=? WHERE id=? AND user_id=?");
                $stmt->bind_param("ssssisssisi", $name, $gender, $pob, $dob, $alive, $note, $photoPath, $child_order, $myName, $id, $targetUserId);
                

                if ($stmt->execute()) $bio_success = "Update berhasil."; else $bio_error = $stmt->error;
                $stmt->close();
                
                $action = 'bio'; $_GET['id'] = $id; $_GET['mode'] = 'view';
            } else {
                $bio_error = "Data tidak ditemukan atau bukan milik Anda.";
            }
        }
    }
    
    // 4. BUAT RELASI BARU (Manual)
    elseif (isset($_POST['create_relation'])) {
        $pid = intval($_POST['person_id']); 
        $rid = intval($_POST['related_person_id']); 
        $rtype = $_POST['relation_type'];
        
        if ($pid > 0 && $rid > 0 && $rtype) {
             // Insert A -> B
             $mysqli->query("INSERT IGNORE INTO relations (user_id, person_id, related_person_id, relation_type) VALUES ($targetUserId, $pid, $rid, '$rtype')");
             
             // Insert B -> A (Kebalikannya)
             $revType = null;
             if ($rtype == 'ayah' || $rtype == 'ibu') $revType = 'anak';
             elseif ($rtype == 'anak') {
                 $pg = $mysqli->query("SELECT gender FROM persons WHERE id=$pid")->fetch_assoc()['gender']??'';
                 $revType = (strtoupper($pg)=='P') ? 'ibu' : 'ayah';
             } elseif ($rtype == 'saudara') $revType = 'saudara';
             elseif ($rtype == 'pasangan') $revType = 'pasangan';
             
             if ($revType) {
                 $mysqli->query("INSERT IGNORE INTO relations (user_id, person_id, related_person_id, relation_type) VALUES ($targetUserId, $rid, $pid, '$revType')");
             }
             redirect("?action=bio&id=$pid&mode=view");
        }
    }
}

// --- LOGIKA PROYEK KELUARGA (TREE) ---

// 1. BUAT TREE BARU
if (isset($_POST['create_tree'])) {
    $treeName = trim($_POST['tree_name']);
    if ($treeName) {
        $stmt = $mysqli->prepare("INSERT INTO family_trees (user_id, name) VALUES (?, ?)");
        $stmt->bind_param("is", $myUserId, $treeName);
        if($stmt->execute()) $success = "Proyek '$treeName' berhasil dibuat.";
        else $error = "Gagal membuat proyek.";
    }
}

// 2. UPDATE NAMA TREE
if (isset($_POST['update_tree'])) {
    $treeId = intval($_POST['tree_id']);
    $newName = trim($_POST['tree_name']);
    // Pastikan milik user sendiri
    $check = $mysqli->query("SELECT id FROM family_trees WHERE id=$treeId AND user_id=$myUserId");
    if ($check->num_rows > 0 && $newName) {
        $stmt = $mysqli->prepare("UPDATE family_trees SET name=? WHERE id=?");
        $stmt->bind_param("si", $newName, $treeId);
        $stmt->execute();
        $success = "Nama proyek diperbarui.";
    }
}


// ... (setelah logika update_tree)

// 3. HAPUS TREE (PROYEK KELUARGA)
if (isset($_POST['delete_tree'])) {
    $delTreeId = intval($_POST['tree_id']);
    
    // Verifikasi kepemilikan sebelum menghapus
    $check = $mysqli->query("SELECT id FROM family_trees WHERE id=$delTreeId AND user_id=$myUserId");
    if ($check->num_rows > 0) {
        // Hapus Relasi yang melibatkan orang di tree ini
        $mysqli->query("DELETE FROM relations WHERE person_id IN (SELECT id FROM persons WHERE tree_id=$delTreeId) OR related_person_id IN (SELECT id FROM persons WHERE tree_id=$delTreeId)");
        
        // Hapus Orang-orang di tree ini
        $mysqli->query("DELETE FROM persons WHERE tree_id=$delTreeId");
        
        // Terakhir, Hapus Tree-nya
        $stmt = $mysqli->prepare("DELETE FROM family_trees WHERE id=?");
        $stmt->bind_param("i", $delTreeId);
        
        if ($stmt->execute()) {
            $success = "Proyek keluarga berhasil dihapus selamanya.";
            // Reset session jika yang dihapus adalah tree yang sedang aktif
            if (($_SESSION['current_tree_id'] ?? 0) == $delTreeId) {
                unset($_SESSION['current_tree_id']);
                unset($_SESSION['current_tree_name']);
            }
        } else {
            $error = "Gagal menghapus proyek.";
        }
        $stmt->close();
    } else {
        $error = "Data tidak ditemukan atau akses ditolak.";
    }
}

// --- 4. LOGIKA SHARE / UNDANG KOLABORATOR (UPDATED) ---
$shareStatus = null; // Variabel untuk SweetAlert

if (isset($_POST['share_tree'])) {
    $treeId = intval($_POST['tree_id']);
    $email  = trim($_POST['email']);
    
    // Cek Owner
    $checkOwner = $mysqli->query("SELECT id FROM family_trees WHERE id=$treeId AND user_id=$myUserId");
    
    if ($checkOwner->num_rows > 0) {
        // 1. Cek apakah email terdaftar di sistem?
        $resUser = $mysqli->query("SELECT id, name FROM users WHERE email = '$email'");
        
        if ($resUser->num_rows > 0) {
            // === KASUS A: USER SUDAH TERDAFTAR ===
            $uData = $resUser->fetch_assoc();
            $collabId = $uData['id'];
            
            if ($collabId == $myUserId) {
                $shareStatus = ['icon'=>'error', 'title'=>'Gagal', 'text'=>'Anda tidak bisa mengundang diri sendiri.'];
            } else {
                $stmt = $mysqli->prepare("INSERT IGNORE INTO tree_collaborators (tree_id, user_id, role) VALUES (?, ?, 'editor')");
                $stmt->bind_param("ii", $treeId, $collabId);
                $stmt->execute();
                
                if ($stmt->affected_rows > 0) {
                    $shareStatus = ['icon'=>'success', 'title'=>'Berhasil!', 'text'=>'User '.htmlspecialchars($uData['name']).' telah ditambahkan sebagai editor.'];
                    fh_send_notification($mysqli, $collabId, "Undangan Kolaborasi 🤝", "Anda diundang untuk mengedit pohon keluarga.", "success");
                } else {
                    $shareStatus = ['icon'=>'warning', 'title'=>'Sudah Ada', 'text'=>'User tersebut sudah menjadi kolaborator.'];
                }
            }
        } else {
            // === KASUS B: USER BELUM TERDAFTAR (KIRIM UNDANGAN) ===
            // Di sini kita gunakan fungsi mail() PHP standar. 
            // Note: Ini hanya akan berhasil jika server hosting Anda mendukung pengiriman email.
            
            $subject = "Undangan Bergabung di FamilyHood";
            $message = "Halo,\n\nAnda diundang untuk mengelola silsilah keluarga di FamilyHood.\nSilakan daftar di aplikasi kami menggunakan email ini ($email) untuk mulai berkolaborasi.\n\nSalam,\nFamilyHood Team";
            $headers = "From: no-reply@familyhood.com";
            
            // @mail($email, $subject, $message, $headers); // Uncomment jika server mail aktif
            
            // Kita tampilkan popup sukses seolah-olah email terkirim
            $shareStatus = ['icon'=>'info', 'title'=>'Undangan Dikirim', 'text'=>'Email ini belum terdaftar di aplikasi. Sebuah email undangan pendaftaran telah dikirim ke '.$email];
        }
    } else {
        $shareStatus = ['icon'=>'error', 'title'=>'Ditolak', 'text'=>'Hanya pemilik utama yang bisa mengundang.'];
    }
}

// --- 5. HAPUS KOLABORATOR (Tetap) ---
if (isset($_POST['remove_collab'])) {
    $treeId = intval($_POST['tree_id']);
    $collabUserId = intval($_POST['collab_user_id']);
    $checkOwner = $mysqli->query("SELECT id FROM family_trees WHERE id=$treeId AND user_id=$myUserId");
    if ($checkOwner->num_rows > 0) {
        $mysqli->query("DELETE FROM tree_collaborators WHERE tree_id=$treeId AND user_id=$collabUserId");
        $success = "Akses kolaborator dicabut.";
    }
}



// --- 5. HAPUS KOLABORATOR ---
if (isset($_POST['remove_collab'])) {
    $treeId = intval($_POST['tree_id']);
    $collabUserId = intval($_POST['collab_user_id']);
    
    // Cek Owner
    $checkOwner = $mysqli->query("SELECT id FROM family_trees WHERE id=$treeId AND user_id=$myUserId");
    if ($checkOwner->num_rows > 0) {
        $mysqli->query("DELETE FROM tree_collaborators WHERE tree_id=$treeId AND user_id=$collabUserId");
        $success = "Akses kolaborator dicabut.";
    }
}

// 3. LOGIKA MASUK KE PROYEK (VIEW_TREE - UPDATED FOR COLLABORATION)
if ($action === 'view_tree') {
    $treeId = intval($_GET['tree_id'] ?? 0);
    
    // Cek: Apakah saya Pemilik? ATAU Apakah saya Kolaborator?
    $isOwner = false;
    $isCollab = false;
    
    // 1. Cek Pemilik
    $check1 = $mysqli->query("SELECT id, name, user_id FROM family_trees WHERE id=$treeId AND user_id=$myUserId");
    if ($row = $check1->fetch_assoc()) {
        $isOwner = true;
        $treeName = $row['name'];
        $treeOwnerId = $row['user_id'];
    } else {
        // 2. Cek Kolaborator
        $check2 = $mysqli->query("SELECT t.id, t.name, t.user_id FROM family_trees t 
                                  JOIN tree_collaborators c ON c.tree_id = t.id 
                                  WHERE t.id=$treeId AND c.user_id=$myUserId");
        if ($row = $check2->fetch_assoc()) {
            $isCollab = true;
            $treeName = $row['name'];
            $treeOwnerId = $row['user_id']; // Pemilik Asli (bukan saya)
        }
    }

    if ($isOwner || $isCollab) {
        // Simpan ID Tree di session
        $_SESSION['current_tree_id'] = $treeId;
        $_SESSION['current_tree_name'] = $treeName;
        $_SESSION['current_tree_owner_id'] = $treeOwnerId; // PENTING: ID Pemilik Asli
        
        // Simpan status saya di tree ini (untuk UI nanti)
        $_SESSION['is_tree_owner'] = $isOwner;
        
        header("Location: ?action=home"); 
        exit;
    } else {
        $error = "Akses ditolak atau proyek tidak ditemukan.";
        $action = 'home'; 
    }
}


// --- LOGIKA HAPUS DATA (DELETE) ---

// Hapus Relasi
if (isset($_GET['delete_rel']) && $action === 'bio') {
    if ($isViewingOthers) die("Akses Ditolak.");
    
    $relId = intval($_GET['delete_rel']); 
    $pid = intval($_GET['id']);
    
    // Cek kepemilikan relasi
    $r = $mysqli->query("SELECT * FROM relations WHERE id=$relId AND user_id=$targetUserId")->fetch_assoc();
    if ($r) {
        $rid = $r['related_person_id']; 
        $rtype = $r['relation_type'];
        
        // Hapus relasi ini
        $mysqli->query("DELETE FROM relations WHERE id=$relId");
        
        // Hapus relasi sebaliknya (B -> A)
        $revType = null;
        if ($rtype == 'ayah' || $rtype == 'ibu') $revType = 'anak';
        elseif ($rtype == 'anak') {
             $pg = $mysqli->query("SELECT gender FROM persons WHERE id=$r[person_id]")->fetch_assoc()['gender']??'';
             $revType = (strtoupper($pg)=='P') ? 'ibu' : 'ayah';
        } elseif ($rtype == 'saudara') $revType = 'saudara';
        elseif ($rtype == 'pasangan') $revType = 'pasangan';
        
        if ($revType) {
            $mysqli->query("DELETE FROM relations WHERE person_id=$rid AND related_person_id=$pid AND relation_type='$revType' AND user_id=$targetUserId");
        }
    }
    redirect("?action=bio&id=$pid&mode=view");
}

// Hapus Orang
if (isset($_GET['delete_person'])) {
    if ($isViewingOthers) die("Akses Ditolak.");
    
    $id = intval($_GET['delete_person']);
    
    // Cek kepemilikan
    $p = $mysqli->query("SELECT photo FROM persons WHERE id=$id AND user_id=$targetUserId")->fetch_assoc();
    
    if ($p) {
        if ($p['photo']) @unlink(__DIR__ . '/' . $p['photo']);
        
        // Hapus semua relasi terkait orang ini (milik user ini)
        $mysqli->query("DELETE FROM relations WHERE (person_id=$id OR related_person_id=$id) AND user_id=$targetUserId");
        
        // Hapus orangnya
        $mysqli->query("DELETE FROM persons WHERE id=$id AND user_id=$targetUserId");
        
        $success = "Anggota dihapus."; 
        $action = 'home';
    } else {
        $error = "Gagal menghapus: Data tidak ditemukan atau bukan milik Anda.";
    }
}

// if ($action === 'home') { $allPersons = fh_get_all_persons($mysqli, $targetUserId); }
if ($action === 'add_person') { 
    $activeTreeId = $_SESSION['current_tree_id'] ?? 0;
    $allPersons = fh_get_persons_by_tree($mysqli, $activeTreeId); 
}

// 3. Action Bio butuh otherPersons untuk dropdown relasi, ambil dari Tree milik orang tersebut
if ($action === 'bio') {
    $id = intval($_GET['id']??0);
    if ($id > 0) {
        $currentPerson = $mysqli->query("SELECT * FROM persons WHERE id=$id")->fetch_assoc();
        if ($currentPerson) {
            $res = $mysqli->query("SELECT r.id, r.relation_type, r.related_person_id, p.name AS related_name, p.gender AS related_gender, p.photo AS related_photo FROM relations r JOIN persons p ON p.id = r.related_person_id WHERE r.person_id=$id ORDER BY FIELD(r.relation_type,'ayah','ibu','anak','saudara','pasangan'), p.name");
            $relations = $res->fetch_all(MYSQLI_ASSOC);
            
            // PERBAIKAN DI SINI: Ambil orang lain dalam satu tree yang sama
            $otherPersons = fh_get_persons_by_tree($mysqli, $currentPerson['tree_id']); 
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>familyHood - Pohon Keluarga</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <link rel="icon" href="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA1MTIgNTEyIiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSI1MTIiIGhlaWdodD0iNTEyIiByeD0iMTIwIiBmaWxsPSJ3aGl0ZSIvPjxwYXRoIGQ9Ik0yNTYgMTUwVjI1MCIgc3Ryb2tlPSIjNGY0NmU1IiBzdHJva2Utd2lkdGg9IjMyIiBzdHJva2UtbGluZWNhcD0icm91bmQiLz48cGF0aCBkPSJNMjU2IDI1MEwxNTAgMzQwIiBzdHJva2U9IiM0ZjQ2ZTUiIHN0cm9rZS13aWR0aD0iMzIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPjxwYXRoIGQ9Ik0yNTYgMjUwTDM2MiAzNDAiIHN0cm9rZT0iIzRmNDZlNSIgc3Ryb2tlLXdpZHRoPSIzMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIi8+PGNpcmNsZSBjeD0iMjU2IiBjeT0iMTQwIiByPSI2MCIgZmlsbD0iIzRmNDZlNSIvPjxjaXJjbGUgY3g9IjE1MCIgY3k9IjM2MCIgcj0iNTAiIGZpbGw9IiM0ZjQ2ZTUiIGZpbGwtb3BhY2l0eT0iMC44Ii8+PGNpcmNsZSBjeD0iMzYyIiBjeT0iMzYwIiByPSI1MCIgZmlsbD0iIzRmNDZlNSIgZmlsbC1vcGFjaXR5PSIwLjgiLz48L3N2Zz4=" type="image/svg+xml">
    <style>
    /* Efek Berdenyut untuk Orang yang di-Fokuskan */
    .node-focused {
        border: 3px solid #4f46e5 !important;
        background-color: #e0e7ff !important;
        box-shadow: 0 0 20px rgba(79, 70, 229, 0.6) !important;
        transform: scale(1.15) !important;
        z-index: 10;
        animation: pulseFocus 2s infinite;
    }

    @keyframes pulseFocus {
        0% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.7); }
        70% { box-shadow: 0 0 0 10px rgba(79, 70, 229, 0); }
        100% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0); }
    }
    
    /* Memisahkan Link Foto dan Nama */
    .node-card-wrapper {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-decoration: none;
    }
    /* --- RESET & UMUM --- */
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f3f4f6; color: #1f2937; }
    
    /* --- UTILS --- */
    a { text-decoration: none; }
    .btn { display: inline-flex; align-items: center; justify-content: center; padding: 8px 16px; border-radius: 8px; border: none; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: 0.2s; gap: 6px; }
    .btn-primary { background: #4f46e5; color: #fff; }
    .btn-primary:hover { background: #4338ca; }
    .btn-secondary { background: #e5e7eb; color: #374151; }
    .btn-danger { background: #fee2e2; color: #991b1b; }
    .btn-success { background: #d1fae5; color: #065f46; }
    .btn-sm { padding: 6px 12px; font-size: 0.8rem; }
    .btn-block { width: 100%; display: flex; }

    .card { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 18px; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08); border: 1px solid rgba(148, 163, 184, 0.24); }
    .modern-card { border-radius: 16px; overflow: hidden; border: 1px solid rgba(79, 70, 229, 0.2); box-shadow: 0 7px 25px rgba(79,70,229,0.16); }

    .page-header { display: flex; flex-direction: column; align-items: flex-start; gap: 12px; padding: 24px 24px; background: transparent; color: #1f2937; border-bottom: 1px solid #f0f1f3; }
    .back-button-link { font-size: 0.85rem; font-weight: 600; color: #6366f1; text-decoration: none; transition: color .2s; }
    .back-button-link:hover { color: #4f46e5; }
    .tree-title { margin: 0; font-size: 1.65rem; font-weight: 700; line-height: 1.2; color: #1f2937; letter-spacing: -0.4px; }
    .btn-add-member { margin-top: 8px; }

    .alert { padding: 12px; border-radius: 8px; margin-bottom: 15px; }
    .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

    /* Form Elements */
    form label { display: block; font-size: 0.9rem; margin: 10px 0 5px; font-weight: 500; color: #4b5563; }
    input, select, textarea { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem; background: #fff; }
    .flex { display: flex; gap: 10px; } .flex-1 { flex: 1; }

    /* --- TAMPILAN MOBILE (DEFAULT) --- */
    body { padding-top: 70px; padding-bottom: 80px; } /* Space untuk Header & Bottom Nav */
    .container { padding: 0 15px; max-width: 100%; }
    
    /* Mobile Header (Fixed Logo) */
    .mobile-header { 
        position: fixed; top: 0; left: 0; right: 0; height: 60px; background: #fff; 
        display: flex; justify-content: center; align-items: center; z-index: 999;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .mobile-header svg { width: 32px; height: 32px; }
    
    /* Mobile Bottom Nav */
    .bottom-nav {
        position: fixed; bottom: 0; left: 0; right: 0; height: 65px; background: #fff; border-top: 1px solid #e5e7eb;
        display: flex; justify-content: space-around; align-items: center; z-index: 999;
    }
    .nav-item { display: flex; flex-direction: column; align-items: center; color: #9ca3af; font-size: 0.7rem; text-decoration: none; }
    .nav-item.active { color: #4f46e5; }
    .nav-item svg { width: 24px; height: 24px; stroke: currentColor; fill: none; stroke-width: 2; margin-bottom: 2px; }
    
    /* Tombol Tambah Tengah (Mobile) */
    .nav-item.add-btn { position: relative; top: -20px; }
    .nav-item.add-btn div { 
        width: 48px; height: 48px; background: #4f46e5; border-radius: 50%; 
        display: flex; align-items: center; justify-content: center; color: #fff; 
        box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3); 
    }
    .nav-item.add-btn svg { stroke: #fff; }

    /* Sembunyikan elemen Desktop di Mobile */
    .desktop-header, .desktop-nav { display: none; }

    /* --- TAMPILAN DESKTOP (LAYAR LEBAR) --- */
    @media (min-width: 768px) {
        body { padding-top: 0; padding-bottom: 40px; } /* Reset padding */
        .container { max-width: 1000px; margin: 20px auto; }
        
        /* Sembunyikan elemen Mobile */
        .mobile-header, .bottom-nav { display: none; }
        
        /* Tampilkan elemen Desktop */
        .desktop-header { 
            display: block; background: linear-gradient(135deg, #4f46e5, #6366f1); color: #fff; 
            padding: 15px 0; margin-bottom: 0; 
        }
        .header-inner { max-width: 1000px; margin: 0 auto; padding: 0 15px; display: flex; justify-content: space-between; align-items: center; }
        
        .desktop-nav { 
            display: block; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 20px; 
        }
        .nav-inner { max-width: 1000px; margin: 0 auto; display: flex; padding: 0 10px; }
        .d-link { 
            padding: 15px 20px; text-decoration: none; color: #4b5563; font-weight: 600; border-bottom: 3px solid transparent; font-size: 0.9rem; 
        }
        .d-link:hover { background: #f9fafb; color: #4f46e5; }
        .d-link.active { border-bottom-color: #4f46e5; color: #4f46e5; }
        .btn-block { width: auto; display: inline-flex; }
    }

    /* --- CSS TREE/POHON (SHARED) - PERBAIKAN --- */
    .tree-container { 
        overflow-x: auto; /* Aktifkan scroll samping */
        text-align: center; 
        padding: 40px 20px; 
        width: 100%;
        background-image: radial-gradient(#e5e7eb 1px, transparent 1px); /* Opsional: Pola titik-titik agar terlihat seperti kanvas */
        background-size: 20px 20px;
    }

    .tree { 
        /* INI KUNCI PERBAIKANNYA: */
        width: max-content;   /* Lebar mengikuti isi konten, bisa melebihi layar */
        min-width: 100%;      /* Minimal selebar layar */
        margin: 0 auto;       /* Posisi tengah */
        display: block;
    }

    .tree ul { 
        padding-top: 20px; 
        position: relative; 
        display: flex;        /* Gunakan Flexbox */
        justify-content: center; 
    }

    .tree li { 
        float: none;          /* HAPUS float:left (ini penyebab bug layout lama) */
        text-align: center; 
        list-style-type: none; 
        position: relative; 
        padding: 20px 5px 0 5px; 
        flex-shrink: 0;       /* Mencegah kartu mengecil/gepeng saat ramai */
    }

    /* Garis Penghubung (Line Connectors) */
    .tree li::before, .tree li::after { 
        content: ''; 
        position: absolute; 
        top: 0; 
        right: 50%; 
        border-top: 2px solid #94a3b8; /* Warna garis diperjelas */
        width: 50%; 
        height: 20px; 
    }
    .tree li::after { 
        right: auto; 
        left: 50%; 
        border-left: 2px solid #94a3b8; 
    }
    .tree li:only-child::after, .tree li:only-child::before { display: none; }
    .tree li:only-child { padding-top: 0; }
    .tree li:first-child::before, .tree li:last-child::after { border: 0 none; }
    .tree li:last-child::before { 
        border-right: 2px solid #94a3b8; 
        border-radius: 0 5px 0 0; 
    }
    .tree li:first-child::after { 
        border-radius: 5px 0 0 0; 
    }
    .tree ul ul::before { 
        content: ''; 
        position: absolute; 
        top: 0; 
        left: 50%; 
        border-left: 2px solid #94a3b8; 
        width: 0; 
        height: 20px; 
    }
    
    /* Kartu Orang di Web */
    .tree-node-web { text-decoration: none; display: inline-block; }
    .tree-node-web.node-deceased .node-card-content { opacity: 0.7; background: #f8f8f8; }
    .tree-node-web.node-deceased .web-photo { filter: grayscale(100%); opacity: 0.8; border-color: #9ca3af; }
    .tree-node-web.node-deceased .web-name { color: #6b7280; font-weight: 700; }
    .tree-node-web.node-deceased::after { content: '†'; position: absolute; top: -8px; right: -8px; background: #dc2626; color: white; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: bold; }
    
    .node-card-content { 
        background: #fff; 
        border: 1px solid #cbd5e1; 
        border-radius: 12px; 
        padding: 8px 10px; 
        min-width: 100px; /* Lebar minimum agar nama panjang muat */
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); 
        position: relative; 
        z-index: 2; 
        transition: transform 0.2s;
    }
    .node-card-content:hover { transform: scale(1.05); border-color: #4f46e5; }
    .node-card-content.highlight { border-color: #4f46e5; background: #eef2ff; border-width: 2px; }
    
    .web-photo { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: filter 0.2s; }
    .web-name { font-size: 0.8rem; font-weight: 700; display: block; line-height: 1.3; margin-top: 6px; color: #1e293b; transition: all 0.2s; }
    
    .spouse-connector-web { 
        width: 20px; /* Jarak suami istri sedikit diperlebar */
        height: 2px; 
        background: #ef4444; 
        display: inline-block; 
        vertical-align: middle; 
        margin: 0 5px;
    }
    
    /* Other Styles */
    .person-list { list-style: none; padding: 0; margin: 0; }
    .person-item { display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
    .person-avatar { width: 40px; height: 40px; border-radius: 50%; background: #eee; margin-right: 10px; overflow: hidden; flex-shrink: 0; }
    .person-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .person-info { flex: 1; }
    .person-name { font-weight: 600; font-size: 0.95rem; }
    .pill-menu { display:flex; gap:8px; overflow-x:auto; padding-bottom:10px; }
    .pill-item { white-space:nowrap; padding:6px 12px; border-radius:99px; background:#fff; border:1px solid #e5e7eb; color:#374151; text-decoration:none; font-size:0.85rem; }
    .pill-item.active { background:#4f46e5; color:#fff; }
    
    /* Menu List di Halaman Akun */
    .menu-list a { display: flex; align-items: center; padding: 15px; border-bottom: 1px solid #f3f4f6; color: #374151; font-weight: 500; }
    .menu-icon { margin-right: 10px; font-size: 1.2rem; }
    
    /* --- MODERN LIST ITEM STYLING --- */
    .person-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px; /* Memberi ruang di dalam */
        margin-bottom: 8px; /* Jarak antar item */
        border-radius: 12px; /* Sudut membulat modern */
        background: #fff; /* Warna dasar */
        border: 1px solid #f3f4f6; /* Garis tipis */
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); /* Animasi halus */
    }
    
    /* Efek Hover: Terangkat & Bayangan */
    .person-item:hover {
        transform: translateY(-3px); /* Naik sedikit */
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025); /* Bayangan lembut */
        border-color: #e5e7eb;
        background: #fafafa; /* Sedikit lebih gelap */
    }
    
    /* Efek Klik (Active): Sedikit tertekan */
    .person-item:active {
        transform: scale(0.98); /* Efek pencet tombol */
    }
    
    /* Styling Link Area Kiri */
    .person-left {
        display: flex;
        align-items: center;
        gap: 15px;
        flex: 1;
        min-width: 0;
        text-decoration: none; /* Hapus garis bawah link */
        color: inherit; /* Ikuti warna teks induk */
        padding: 5px; /* Area klik lebih luas */
    }
    
    /* Avatar styling */
    .person-avatar {
        width: 50px; /* Sedikit lebih besar */
        height: 50px;
        border-radius: 50%;
        background: #f3f4f6;
        overflow: hidden;
        flex-shrink: 0;
        border: 2px solid #fff; /* Border putih agar rapi */
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    
    /* Typography Modern */
    .person-name {
        font-size: 1.05rem;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 2px;
        display: block;
    }
    
    .person-note {
        font-size: 0.85rem;
        color: #6b7280;
        font-weight: 400;
    }
    
    /* Tombol Hapus */
    .person-actions {
        padding-left: 10px;
    }
    
    /* --- PERBAIKAN TAMPILAN TOOLBAR EXPORT --- */
    .export-toolbar {
        display: flex;
        flex-wrap: wrap;            /* Agar turun ke bawah jika layar sempit */
        justify-content: space-between; 
        align-items: center;
        gap: 15px;                  /* Jarak antara Judul dan Tombol */
        margin-bottom: 20px;        /* Jarak ke bawah (ke filter) */
        padding-bottom: 15px;       /* Ruang dalam bawah */
        border-bottom: 2px dashed #f3f4f6; /* Garis pemisah tipis */
    }

    .export-label {
        font-size: 1.1rem;
        font-weight: 700;
        color: #374151;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .export-actions {
        display: flex;
        gap: 10px; /* Memberi jarak antar tombol Excel, Word, PDF */
    }

    /* Mempercantik Pill Menu (Filter) */
    .pill-menu {
        display: flex;
        gap: 10px; /* Jarak antar pilihan filter lebih renggang */
        overflow-x: auto;
        padding-bottom: 10px;
        margin-top: 10px;
    }
    
    .pill-item {
        white-space: nowrap;
        padding: 8px 16px; /* Tombol filter lebih besar sedikit */
        border-radius: 99px;
        background: #fff;
        border: 1px solid #d1d5db;
        color: #4b5563;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 500;
        transition: all 0.2s;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    
    .pill-item:hover {
        border-color: #4f46e5;
        color: #4f46e5;
    }

    .pill-item.active {
        background: #4f46e5;
        color: #fff;
        border-color: #4f46e5;
        box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);
    }
    .link-name {
        color: #007bff;
        text-decoration: none;
        font-weight: 600;
    }
    
    .link-name:hover {
        text-decoration: underline;
    }
    
    .card-relations {
    border-radius: 18px;
    padding: 16px 18px 14px;
    background: linear-gradient(145deg, #ffffff, #f7fafc);
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
    border: 1px solid rgba(148, 163, 184, 0.25);
    margin-bottom: 24px;
    }
    
    .card-relations-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }
    
    .card-relations .section-title {
        font-size: 1.1rem;
        margin: 0;
        font-weight: 700;
        color: #0f172a;
    }
    
    .section-subtitle {
        margin: 2px 0 0;
        font-size: 0.8rem;
        color: #6b7280;
    }
    
    .badge-relations {
        font-size: 0.75rem;
        padding: 4px 10px;
        border-radius: 999px;
        background: rgba(59, 130, 246, 0.08);
        color: #1d4ed8;
        font-weight: 600;
        border: 1px solid rgba(59, 130, 246, 0.15);
        white-space: nowrap;
    }
    
    .empty-relations {
        padding: 20px 12px 4px;
        text-align: center;
        color: #6b7280;
        font-size: 0.9rem;
    }
    
    .empty-relations .empty-icon {
        font-size: 1.9rem;
        margin-bottom: 6px;
    }
    
    /* Table wrapper agar responsive di HP */
    .table-relations-wrapper {
        width: 100%;
        overflow-x: auto;
        margin-top: 6px;
    }
    
    /* Tabel modern */
    .relations-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.88rem;
    }
    
    .relations-table thead tr {
        background: #f1f5f9;
    }
    
    .relations-table th,
    .relations-table td {
        padding: 8px 10px;
    }
    
    .relations-table th {
        text-align: left;
        font-weight: 600;
        font-size: 0.78rem;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        color: #6b7280;
        border-bottom: 1px solid rgba(148, 163, 184, 0.45);
    }
    
    .relations-table tbody tr.relation-row {
        cursor: pointer;
        transition: background 0.18s ease, transform 0.12s ease, box-shadow 0.12s ease;
    }
    
    .relations-table tbody tr.relation-row:nth-child(even) {
        background: #ffffff;
    }
    
    .relations-table tbody tr.relation-row:nth-child(odd) {
        background: #f9fafb;
    }
    
    .relations-table tbody tr.relation-row:hover {
        background: #e0f2fe;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.18);
    }
    
    /* Isi kolom nama + avatar */
    .relation-person {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .relation-avatar {
        width: 34px;
        height: 34px;
        border-radius: 999px;
        background: linear-gradient(135deg, #3b82f6, #6366f1);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        font-weight: 700;
        color: white;
        flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.4);
    }
    
    .relation-text {
        display: flex;
        flex-direction: column;
    }
    
    .relation-name {
        font-weight: 600;
        color: #0f172a;
        text-decoration: none;
        transition: color 0.15s ease;
    }
    
    .relation-name:hover {
        color: #1d4ed8;
        text-decoration: underline;
    }
    
    .relation-id {
        font-size: 0.7rem;
        color: #9ca3af;
    }
    
    /* Badge tipe relasi */
    .relation-type-pill {
        display: inline-flex;
        align-items: center;
        padding: 3px 9px;
        border-radius: 999px;
        background: rgba(16, 185, 129, 0.1);
        color: #047857;
        font-size: 0.75rem;
        font-weight: 600;
        border: 1px solid rgba(16, 185, 129, 0.25);
    }
    
    /* Tombol chip aksi */
    .relation-actions {
        display: inline-flex;
        gap: 6px;
    }
    
    .btn-chip {
        border-radius: 999px;
        padding: 4px 10px;
        font-size: 0.75rem;
        border: none;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-weight: 500;
        transition: background 0.16s ease, transform 0.12s ease, box-shadow 0.12s ease;
        cursor: pointer;
        white-space: nowrap;
    }
    
    .btn-chip-view {
        background: #eff6ff;
        color: #1d4ed8;
    }
    
    .btn-chip-view:hover {
        background: #dbeafe;
        transform: translateY(-1px);
        box-shadow: 0 3px 6px rgba(37, 99, 235, 0.25);
    }
    
    .btn-chip-delete {
        background: #fef2f2;
        color: #b91c1c;
    }
    
    .btn-chip-delete:hover {
        background: #fee2e2;
        transform: translateY(-1px);
        box-shadow: 0 3px 6px rgba(185, 28, 28, 0.25);
    }
    
    /* Responsif HP */
    @media (max-width: 640px) {
        .card-relations {
            padding: 12px 12px 10px;
            border-radius: 16px;
        }
    
        .relations-table th:nth-child(3),
        .relations-table td:nth-child(3) {
            white-space: nowrap;
        }
    
        .relation-id {
            display: none; /* kalau sempit, ID disembunyikan */
        }
    }
    
    /* --- TAMBAHAN: MODERN LOADER & TRANSITION --- */
    #page-loader {
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(255, 255, 255, 0.95); /* Latar putih sedikit transparan */
        backdrop-filter: blur(5px); /* Efek blur di belakang */
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: opacity 0.3s ease, visibility 0.3s ease;
        opacity: 0;
        visibility: hidden;
    }

    #page-loader.active {
        opacity: 1;
        visibility: visible;
    }

    .loader-content {
        width: 80px;
        height: 80px;
        animation: breathe 1.5s infinite ease-in-out;
    }

    @keyframes breathe {
        0% { transform: scale(0.9); opacity: 0.8; }
        50% { transform: scale(1.1); opacity: 1; filter: drop-shadow(0 0 15px rgba(79, 70, 229, 0.4)); }
        100% { transform: scale(0.9); opacity: 0.8; }
    }
    
    /* Animasi Fading untuk Konten */
    .fade-in {
        animation: fadeIn 0.4s ease-out forwards;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    /* --- CSS TOMBOL TREE ACTION --- */
    .node-card-content {
        position: relative; /* Wajib agar tombol absolute mengacu ke kotak ini */
        overflow: visible !important; /* Agar tombol yang keluar garis tetap terlihat */
    }

    .tree-action-btn {
        position: absolute;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        font-size: 12px;
        color: white;
        border: 2px solid white;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        z-index: 20; /* Pastikan di atas foto */
        cursor: pointer;
        transition: transform 0.2s;
    }
    
    .tree-action-btn:hover { transform: scale(1.1); }

    /* Tombol Edit (Kiri Atas) - Warna Kuning/Oranye */
    .btn-tree-edit {
        top: -8px;
        left: -8px;
        background-color: #f59e0b; 
    }

    /* Tombol Tambah (Kanan Atas) - Warna Hijau */
    .btn-tree-add {
        top: -8px;
        right: -8px;
        background-color: #10b981;
    }


</style>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

    <div id="page-loader">
        <div class="loader-content">
            <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="512" height="512" rx="100" fill="#4f46e5"/>
                <path d="M256 150V250" stroke="white" stroke-width="32" stroke-linecap="round"/>
                <path d="M256 250L150 340" stroke="white" stroke-width="32" stroke-linecap="round"/>
                <path d="M256 250L362 340" stroke="white" stroke-width="32" stroke-linecap="round"/>
                <circle cx="256" cy="140" r="60" fill="white"/>
            </svg>
        </div>
    </div>

    <header class="mobile-header">
        <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="512" height="512" rx="100" fill="#4f46e5"/>
            <path d="M256 150V250" stroke="white" stroke-width="32" stroke-linecap="round"/>
            <path d="M256 250L150 340" stroke="white" stroke-width="32" stroke-linecap="round"/>
            <path d="M256 250L362 340" stroke="white" stroke-width="32" stroke-linecap="round"/>
            <circle cx="256" cy="140" r="60" fill="white"/>
        </svg>
    </header>

    <div class="desktop-header">
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <svg width="30" height="30" viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="512" height="512" rx="100" fill="white"/>
                    <path d="M256 150V250" stroke="#4f46e5" stroke-width="32" stroke-linecap="round"/>
                    <path d="M256 250L150 340" stroke="#4f46e5" stroke-width="32" stroke-linecap="round"/>
                    <path d="M256 250L362 340" stroke="#4f46e5" stroke-width="32" stroke-linecap="round"/>
                    <circle cx="256" cy="140" r="60" fill="#4f46e5"/>
                </svg>
                <h1 style="font-size:1.2rem; margin:0;">familyHood</h1>
            </div>
            <div style="font-size:0.9rem;">
                Halo, <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong>
            </div>
        </div>
    </div>

    <div class="desktop-nav">
        <div class="nav-inner">
            <a href="?action=home" class="d-link <?= ($action === 'home') ? 'active' : '' ?>">📋 Home</a>
            <a href="?action=add_person" class="d-link <?= ($action === 'add_person') ? 'active' : '' ?>">➕ Tambah Baru</a>
            <a href="?action=tree" class="d-link <?= ($action === 'tree') ? 'active' : '' ?>">🌳 Pohon Keluarga</a>
            
            <?php if ($isAdmin): ?>
                <a href="?action=admin_users" class="d-link <?= ($action === 'admin_users') ? 'active' : '' ?>">👑 Admin</a>
            <?php endif; ?>
            
            <a href="?action=settings" class="d-link <?= ($action === 'settings' || $action === 'support') ? 'active' : '' ?>" style="margin-left:auto;">⚙️ Akun & Bantuan</a>
            <a href="?action=notifications" class="d-link <?= ($action === 'notifications') ? 'active' : '' ?>">🔔 Info</a>
            <a href="?action=logout" class="d-link" onclick="return confirm('Keluar?')" style="color:#dc2626;">🚪 Logout</a>
        </div>
    </div>

    <div class="container" id="main-content">
    <?php if ($error): ?> <div class="alert alert-error"><?= htmlspecialchars($error) ?></div> <?php endif; ?>
    <?php if ($success): ?> <div class="alert alert-success"><?= htmlspecialchars($success) ?></div> <?php endif; ?>

       <?php if ($action === 'home'): ?>
    
        <?php 
        // Cek apakah user sudah memilih pohon keluarga?
        $activeTreeId = $_SESSION['current_tree_id'] ?? 0;
        ?>

        <?php if ($activeTreeId == 0 && !$isViewingOthers): ?>
            
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h2>Daftar Keluarga Besar</h2>
                    <button onclick="document.getElementById('modalCreate').style.display='flex'" class="btn btn-primary btn-sm">+ Buat Baru</button>
                </div>
        
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap:15px;">
                    <?php
                        // Tampilkan Tree milik sendiri DAN Tree yang dibagikan (Union Logic)
                        $sqlTrees = "
                            SELECT t.*, 'owner' as role, (SELECT COUNT(*) FROM persons p WHERE p.tree_id = t.id) as total 
                            FROM family_trees t WHERE t.user_id = $myUserId
                            UNION
                            SELECT t.*, c.role as role, (SELECT COUNT(*) FROM persons p WHERE p.tree_id = t.id) as total 
                            FROM family_trees t 
                            JOIN tree_collaborators c ON c.tree_id = t.id 
                            WHERE c.user_id = $myUserId
                            ORDER BY id DESC
                        ";
                        $trees = $mysqli->query($sqlTrees);
                        
                        // --- MULAI LOOPING ---
                        while($t = $trees->fetch_assoc()):
                        ?>
                            <div style="border:1px solid #e5e7eb; border-radius:10px; padding:15px; background:#fff; position:relative;">
                                
                                <div style="position:absolute; top:10px; right:10px; display:flex; gap:5px;">
                                    <?php if($t['role'] === 'owner'): ?>
                                        <button onclick="openShareModal(<?= $t['id'] ?>, '<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>')" 
                                                style="border:none; background:#e0e7ff; width:30px; height:30px; border-radius:50%; cursor:pointer; color:#4338ca; display:flex; align-items:center; justify-content:center;" title="Bagikan / Share">
                                            🔗
                                        </button>
                                        
                                        <button onclick="editTree(<?= $t['id'] ?>, '<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>')" 
                                                style="border:none; background:#f3f4f6; width:30px; height:30px; border-radius:50%; cursor:pointer; color:#4b5563; display:flex; align-items:center; justify-content:center;">
                                            ✎
                                        </button>
                                        
                                        <button onclick="deleteTree(<?= $t['id'] ?>, '<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>')" 
                                                style="border:none; background:#fee2e2; width:30px; height:30px; border-radius:50%; cursor:pointer; color:#991b1b; display:flex; align-items:center; justify-content:center;">
                                            🗑
                                        </button>
                                    
                                    <?php else: ?>
                                        <span style="font-size:0.75rem; background:#d1fae5; color:#065f46; padding:2px 6px; border-radius:4px;">Shared with you</span>
                                    <?php endif; ?>
                                </div>
                                
                                <a href="?action=view_tree&tree_id=<?= $t['id'] ?>" style="text-decoration:none; color:inherit; display:block; padding-top:10px;">
                                    <div style="font-size:2rem; margin-bottom:10px;">🌳</div>
                                    <h3 style="margin:0 0 5px; font-size:1.1rem; color:#4f46e5; padding-right:80px;"><?= htmlspecialchars($t['name']) ?></h3>
                                    <p style="margin:0; font-size:0.85rem; color:#6b7280;"><?= $t['total'] ?> Anggota</p>
                                </a>
                        
                            </div>
                        <?php endwhile; ?>
                </div>
            </div>

            <div id="modalCreate" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:999;">
                <div style="background:#fff; padding:25px; border-radius:10px; width:90%; max-width:400px;">
                    <h3>Buat Proyek Baru</h3>
                    <form method="post">
                        <input type="hidden" name="create_tree" value="1">
                        <input type="text" name="tree_name" placeholder="Nama Keluarga Besar..." required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:5px;">
                        <div style="text-align:right;">
                            <button type="button" onclick="document.getElementById('modalCreate').style.display='none'" class="btn btn-secondary">Batal</button>
                            <button type="submit" class="btn btn-primary">Simpan</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="modalEdit" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:999;">
                <div style="background:#fff; padding:25px; border-radius:10px; width:90%; max-width:400px;">
                    <h3>Ubah Nama Proyek</h3>
                    <form method="post">
                        <input type="hidden" name="update_tree" value="1">
                        <input type="hidden" name="tree_id" id="edit_tree_id">
                        <input type="text" name="tree_name" id="edit_tree_name" required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:5px;">
                        <div style="text-align:right;">
                            <button type="button" onclick="document.getElementById('modalEdit').style.display='none'" class="btn btn-secondary">Batal</button>
                            <button type="submit" class="btn btn-primary">Simpan</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div id="modalShare" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:999;">
                <div style="background:#fff; padding:25px; border-radius:12px; width:90%; max-width:450px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3 style="margin:0;">Bagikan Keluarga</h3>
                        <button onclick="document.getElementById('modalShare').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#6b7280;">&times;</button>
                    </div>
                    
                    <p style="font-size:0.9rem; color:#6b7280; margin-bottom:15px;">
                        Undang orang lain untuk mengedit <b id="share_tree_name_disp" style="color:#4f46e5;"></b> bersama.
                    </p>
                    
                    <form method="post">
                        <input type="hidden" name="share_tree" value="1">
                        <input type="hidden" name="tree_id" id="share_tree_id">
                        
                        <div style="display:flex; gap:10px;">
                            <input type="email" name="email" required placeholder="Masukkan email teman..." style="flex:1; padding:10px; border:1px solid #d1d5db; border-radius:8px;">
                            <button type="submit" class="btn btn-primary">Undang</button>
                        </div>
                    </form>
            
                    <div style="margin-top:25px; border-top:1px solid #f3f4f6; padding-top:15px;">
                        <h4 style="margin:0 0 10px; font-size:0.9rem; color:#374151;">👥 Siapa yang punya akses?</h4>
                        
                        <ul id="collab_list_container" style="list-style:none; padding:0; margin:0; max-height:200px; overflow-y:auto;">
                            <li style="text-align:center; color:#9ca3af; font-size:0.85rem; padding:10px;">Memuat data...</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div id="modalDelete" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:9999; backdrop-filter:blur(2px);">
                <div style="background:#fff; padding:25px; border-radius:12px; width:90%; max-width:400px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div style="text-align:center; margin-bottom:15px;">
                        <div style="font-size:3rem; margin-bottom:10px;">⚠️</div>
                        <h3 style="margin:0; color:#991b1b;">Hapus Keluarga?</h3>
                        <p style="color:#6b7280; font-size:0.9rem; margin-top:5px;">Anda akan menghapus: <br><strong id="del_tree_name_disp" style="color:#1f2937;"></strong></p>
                    </div>
                    <form method="post">
                        <input type="hidden" name="delete_tree" value="1">
                        <input type="hidden" name="tree_id" id="del_tree_id">
                        
                        <div style="background:#fef2f2; border:1px solid #fecaca; padding:12px; border-radius:8px; font-size:0.85rem; color:#7f1d1d; margin-bottom:20px;">
                            Tindakan ini <strong>tidak dapat dibatalkan</strong>. Semua data anggota keluarga dan foto di dalam proyek ini akan hilang selamanya.
                        </div>
                        
                        <label style="display:flex; align-items:start; gap:10px; cursor:pointer; margin-bottom:20px; font-size:0.9rem; user-select:none;">
                            <input type="checkbox" id="confirm_delete_check" onchange="toggleDeleteBtn(this)" style="width:20px; height:20px; margin-top:2px;">
                            <span>Saya mengerti dan ingin menghapus data ini secara permanen.</span>
                        </label>
            
                        <div style="display:flex; gap:10px;">
                            <button type="button" onclick="document.getElementById('modalDelete').style.display='none'" class="btn btn-secondary flex-1">Batal</button>
                            <button type="submit" id="btn_delete_submit" class="btn btn-danger flex-1" disabled style="opacity:0.5; cursor:not-allowed;">🗑 Hapus</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php else: ?>

            <?php 
                // Tentukan Tree ID yang akan ditampilkan
                $treeIdToDisplay = $activeTreeId;
                $treeNameToDisplay = $_SESSION['current_tree_name'] ?? 'Daftar Anggota';

                // Jika Admin sedang mode View As, gunakan Tree ID dari session admin
                if ($isAdmin && $isViewingOthers) {
                    $treeIdToDisplay = $_SESSION['admin_viewing_tree_id'] ?? 0;
                    $treeNameToDisplay = "Melihat Pohon Keluarga " . htmlspecialchars($_SESSION['admin_viewing_tree_name'] ?? 'User Lain');
                }

                // Ambil data anggota berdasarkan Tree ID yang aktif/diintip
                $allPersons = fh_get_persons_by_tree($mysqli, $treeIdToDisplay); 
            ?>

            <div class="card modern-card">
                <div class="page-header">
                    <a href="?action=reset_tree" class="back-button-link">← Kembali</a>
                    <h2 class="tree-title"><?= htmlspecialchars($treeNameToDisplay) ?></h2>
                    <?php if (!$isViewingOthers): // Hanya tampilkan tombol Tambah jika bukan mode intip Admin ?>
                        <a href="?action=add_person" class="btn btn-primary btn-add-member">+ Anggota</a>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($allPersons)): ?>
                    <p style="text-align:center; padding:20px; color:#6b7280;">Belum ada anggota di keluarga ini.</p>
                <?php else: ?>
                    <ul class="person-list">
                        <?php foreach ($allPersons as $p): ?>
                           <li class="person-item">
                                <a href="?action=bio&id=<?= $p['id'] ?>&mode=view" class="person-left">
                                    <div class="person-avatar">
                                        <?php if (!empty($p['photo'])): ?>
                                            <img src="<?= htmlspecialchars($p['photo']) ?>">
                                        <?php else: ?>
                                            <?= render_avatar_svg($p['gender'] ?? '') ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="person-info">
                                        <span class="person-name"><?= htmlspecialchars($p['name']) ?></span>
                                        <?php if(isset($p['date_of_birth']) && $p['date_of_birth']): ?>
                                            <small style="color:#9ca3af; font-size:0.75rem;">
                                                Lahir: <?= date('Y', strtotime($p['date_of_birth'])) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </a>
                           </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

        <?php endif; ?>
            
            
            
            
        
            
        
        <?php elseif ($action === 'view_tree'): ?>
            
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <div>
                        <a href="?action=home" style="font-size:0.8rem; color:#6b7280;">← Kembali</a>
                        <h2 style="margin-top:5px;"><?= htmlspecialchars($_SESSION['current_tree_name'] ?? 'Home') ?></h2>
                    </div>
                    <a href="?action=add_person" class="btn btn-primary btn-sm">+ Anggota</a>
                </div>
                
                <?php if (empty($allPersons)): ?>
                    <p style="text-align:center; padding:20px;">Belum ada anggota.</p>
                <?php else: ?>
                    <ul class="person-list">
                        <?php foreach ($allPersons as $p): ?>
                           <li class="person-item">
                                <a href="?action=bio&id=<?= $p['id'] ?>&mode=view" class="person-left">
                                    <div class="person-avatar">
                                        <?php if (!empty($p['photo'])): ?>
                                            <img src="<?= htmlspecialchars($p['photo']) ?>">
                                        <?php else: ?>
                                            <?= render_avatar_svg($p['gender'] ?? '') ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="person-info">
                                        <span class="person-name"><?= htmlspecialchars($p['name']) ?></span>
                                        </div>
                                </a>
                                </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        
        <?php elseif ($action === 'add_person'): ?>
        <div class="card">
            
            <?php
                $req_id  = intval($_GET['from_id'] ?? 0); $req_rel = $_GET['relation_type'] ?? ''; $pre_gender = '';
                if ($req_rel === 'ibu') $pre_gender = 'P'; elseif ($req_rel === 'ayah') $pre_gender = 'L';
                elseif ($req_rel === 'pasangan' && $req_id > 0) { $r = $mysqli->query("SELECT gender FROM persons WHERE id=$req_id")->fetch_assoc(); if ($r) $pre_gender = ($r['gender'] === 'L') ? 'P' : 'L'; }
            ?>
            
            <h2>Tambah Anggota Keluarga<?php if($req_rel !== '') { echo ' : ' . ucfirst($req_rel); } ?></h2>
            <form method="post" action="?action=add_person">
                <input type="hidden" name="create_person_full" value="1">
                <input type="hidden" name="from_id" value="<?= $req_id ?>">
                <input type="hidden" name="from_relation_type" value="<?= htmlspecialchars($req_rel) ?>">
                <label>Nama Lengkap</label>
                <input type="text" name="name" required placeholder="Contoh: Budi Santoso">
                <label>Jenis Kelamin</label>
                <div style="display:flex; gap:20px; margin-top:5px; margin-bottom:15px;">
                    <label style="display:flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer; white-space:nowrap;">
                        <input type="radio" name="gender" value="L" required <?= ($pre_gender==='L')?'checked':'' ?>> 
                        Laki-laki
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer; white-space:nowrap;">
                        <input type="radio" name="gender" value="P" required <?= ($pre_gender==='P')?'checked':'' ?>> 
                        Perempuan
                    </label>
                </div>
                <div class="flex">
                    <div class="flex-1"><label>Tempat Lahir</label><input type="text" name="place_of_birth"></div>
                    <div class="flex-1"><label>Tanggal Lahir</label><input type="date" name="date_of_birth"></div>
                </div>
                <label>Status Hidup</label>
                <select name="is_alive"><option value="1">Hidup</option><option value="0">Wafat</option></select>
                <label>Catatan</label>
                <textarea name="note" placeholder="Tambahkan catatan khusus..."></textarea>
                
                <?php if ($req_rel === 'anak'): ?>
                    <label>Anak ke berapa</label>
                    <input type="number" name="child_order" min="1" placeholder="Contoh: 1, 2, 3...">
                <?php endif; ?>
                
                <?php if ($req_rel === 'saudara'): ?>
                    <div class="alert alert-success" style="margin-top:10px;">
                        <label style="margin:0; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px;">
                            <input type="checkbox" name="same_parents" value="1" checked style="width:auto;"> Satu Orang Tua?
                        </label>
                        <label style="margin:0; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px; margin-top:5px;">
                            <input type="checkbox" name="link_siblings" value="1" checked style="width:auto;"> Hubungkan ke saudara lain?
                        </label>
                    </div>
                <?php endif; ?>
                <?php if ($req_rel === 'anak'): ?>
                    <div class="alert alert-success" style="margin-top:10px;">
                        <label style="margin:0; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px;">
                            <input type="checkbox" name="link_spouse" value="1" checked style="width:auto;"> Jadikan pasangan sbg ortu juga?
                        </label>
                        <label style="margin:0; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px; margin-top:5px;">
                            <input type="checkbox" name="link_existing_children" value="1" checked style="width:auto;"> Hubungkan dengan kakak/adik?
                        </label>
                    </div>
                <?php endif; ?>

                <div style="margin-top:20px;">
                    <button type="submit" class="btn btn-primary">Simpan Data</button>
                    <a href="?action=home" class="btn btn-secondary">Batal</a>
                </div>
            </form>
        </div>

    <?php elseif ($action === 'bio' && $currentPerson): ?>
        <?php $mode = $_GET['mode'] ?? 'view'; $isEdit = ($mode === 'edit'); ?>
        <?php if ($bio_error): ?> <div class="alert alert-error"><?= $bio_error ?></div> <?php endif; ?>
        <?php if ($bio_success): ?> <div class="alert alert-success"><?= $bio_success ?></div> <?php endif; ?>

        <div class="bio-grid">
            <div class="card">
                <?php if ($isEdit): ?>
                    <h2 class="section-title">Edit Biografi: <?=htmlspecialchars($currentPerson['name'])?> </h2>
                    <form method="post" action="?action=update_bio" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="<?= $currentPerson['id'] ?>">
                        <div style="text-align:center; margin-bottom:10px;">
                            <img src="<?= !empty($currentPerson['photo']) ? htmlspecialchars($currentPerson['photo']) : 'assets/l.png' ?>" style="width:100px; height:100px; object-fit:cover; border-radius:10px;">
                            <input type="file" name="photo" style="margin-top:5px;">
                        </div>
                        <label>Nama</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($currentPerson['name']) ?>" required>
                        <div class="flex">
                            <div class="flex-1"><label>Tempat</label><input type="text" name="place_of_birth" value="<?= htmlspecialchars($currentPerson['place_of_birth']??'') ?>"></div>
                            <div class="flex-1"><label>Tanggal</label><input type="date" name="date_of_birth" value="<?= htmlspecialchars($currentPerson['date_of_birth']??'') ?>"></div>
                        </div>
                        <div class="flex">
                            <div class="flex-1">
                            <label>Gender</label>
                            <div style="display:flex; gap:15px; margin-top:10px; align-items:center; height:42px;"> <label style="font-weight:normal; display:flex; align-items:center; gap:5px; cursor:pointer;">
                                    <input type="radio" name="gender" value="L" <?= $currentPerson['gender']=='L'?'checked':'' ?>> L
                                </label>
                                <label style="font-weight:normal; display:flex; align-items:center; gap:5px; cursor:pointer;">
                                    <input type="radio" name="gender" value="P" <?= $currentPerson['gender']=='P'?'checked':'' ?>> P
                                </label>
                            </div>
                        </div>
                            <div class="flex-1">
                                <label>Status</label>
                                <select name="is_alive">
                                    <option value="1" <?= $currentPerson['is_alive']==1?'selected':'' ?>>Hidup</option>
                                    <option value="0" <?= $currentPerson['is_alive']==0?'selected':'' ?>>Wafat</option>
                                </select>
                            </div>
                        </div>
                        <label>Catatan</label><textarea name="note"><?= htmlspecialchars($currentPerson['note']??'') ?></textarea>
                        
                        <label>Anak ke berapa (jika berlaku)</label>
                        <input type="number" name="child_order" min="1" value="<?= htmlspecialchars($currentPerson['child_order']??'') ?>" placeholder="Kosongkan jika bukan anak">
                        
                        <div style="margin-top:20px;">
                            <button type="submit" class="btn btn-primary btn-block">💾 Simpan Perubahan</button>
                            <a href="?action=bio&id=<?= $currentPerson['id'] ?>&mode=view" class="btn btn-secondary btn-block" style="margin-top:8px;">Batal</a>
                            
                            <div style="margin-top: 25px; border-top: 1px dashed #cbd5e1; padding-top: 20px;">
                                <a href="?delete_person=<?= $currentPerson['id'] ?>" 
                                   onclick="return confirm('PERINGATAN: \nApakah Anda yakin ingin menghapus <?= htmlspecialchars($currentPerson['name']) ?>?\n\nData yang dihapus tidak bisa dikembalikan.')" 
                                   class="btn btn-danger btn-block">
                                    🗑️ Hapus Orang Ini
                                </a>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <div style="text-align:center; margin-bottom:15px;">
                         <?php $photo = ($currentPerson['gender']=='P') ? 'assets/p.png' : 'assets/l.png'; if ($currentPerson['photo']) $photo = $currentPerson['photo']; ?>
                         <img src="<?= $photo ?>" style="width:120px; height:120px; border-radius:50%; object-fit:cover; border:4px solid #fff; box-shadow:0 2px 10px rgba(0,0,0,0.1); background:#e5e7eb;">
                         <h2 style="margin:10px 0 5px;"><?= htmlspecialchars($currentPerson['name']) ?></h2>
                         <span style="font-size:0.85rem; background:<?= $currentPerson['is_alive']?'#dcfce7':'#fee2e2' ?>; color:<?= $currentPerson['is_alive']?'#166534':'#991b1b' ?>; padding:2px 8px; border-radius:99px;">
                             <?= label_alive($currentPerson['is_alive']) ?>
                         </span>
                    </div>
                    <div style="background:#f9fafb; padding:15px; border-radius:10px; font-size:0.9rem;">
                    <?php if (!empty($currentPerson['last_editor_name'])): ?>
                        <div style="margin-top:10px; text-align:right; font-size:0.75rem; color:#9ca3af; font-style:italic;">
                            📝 Terakhir diedit oleh: <strong><?= htmlspecialchars($currentPerson['last_editor_name']) ?></strong>
                            <?php if(!empty($currentPerson['last_updated_at'])) echo ' (' . date('d/m H:i', strtotime($currentPerson['last_updated_at'])) . ')'; ?>
                        </div>
                    <?php endif; ?>    
                        <p><strong>Lahir:</strong> <?= htmlspecialchars($currentPerson['place_of_birth']??'-') ?>, <?= $currentPerson['date_of_birth'] ? date('d M Y', strtotime($currentPerson['date_of_birth'])) : '-' ?></p>
                        <p><strong>Gender:</strong> <?= label_gender($currentPerson['gender']) ?></p>
                        
                        <?php if($currentPerson['note']): ?>
                            <div style="margin-top:10px; border-top:1px dashed #d1d5db; padding-top:10px;"><strong>Catatan:</strong><br><?= nl2br(htmlspecialchars($currentPerson['note'])) ?></div>
                        <?php endif; ?>
                    
                        <?php if (!empty($currentPerson['last_editor_name'])): ?>
                            <div style="margin-top:15px; padding-top:10px; border-top:2px solid #e5e7eb; text-align:right;">
                                <div style="display:inline-block; text-align:left; background:#fff; border:1px solid #e5e7eb; padding:5px 10px; border-radius:8px;">
                                    <div style="font-size:0.7rem; color:#9ca3af; text-transform:uppercase; letter-spacing:0.5px;">Terakhir diedit oleh:</div>
                                    <div style="font-weight:bold; color:#4b5563; font-size:0.85rem;">
                                        ✏️ <?= htmlspecialchars($currentPerson['last_editor_name']) ?>
                                    </div>
                                    <?php if(!empty($currentPerson['last_updated_at'])): ?>
                                        <div style="font-size:0.7rem; color:#9ca3af; margin-top:2px;">
                                            <?= date('d M Y, H:i', strtotime($currentPerson['last_updated_at'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        </div>
                        <?php if (!$isViewingOthers): ?>
                        <a href="?action=bio&id=<?= $currentPerson['id'] ?>&mode=edit" class="btn btn-primary btn-block" style="margin-top:15px;">✏️ Edit Profil</a>
                        <?php endif; ?>
                <?php endif; ?>
            </div>


            <div class="card">
                    <?php if (!$isViewingOthers): ?>
                    <h3 class="section-title" style="margin-top:20px;">Hubungkan</h3>
                    <?php endif; ?>
                <div style="display:flex; gap:5px; flex-wrap:wrap;"> <a href="?action=add_person&from_id=<?= $currentPerson['id'] ?>&relation_type=ayah" class="btn btn-sm btn-secondary">+ Ayah</a>
                    <a href="?action=add_person&from_id=<?= $currentPerson['id'] ?>&relation_type=ibu" class="btn btn-sm btn-secondary">+ Ibu</a>
                    
                    <a href="?action=add_person&from_id=<?= $currentPerson['id'] ?>&relation_type=pasangan" class="btn btn-sm btn-success">+ Suami/Istri</a>
                    <a href="?action=add_person&from_id=<?= $currentPerson['id'] ?>&relation_type=anak" class="btn btn-sm btn-success">+ Anak</a>
                    <a href="?action=add_person&from_id=<?= $currentPerson['id'] ?>&relation_type=saudara" class="btn btn-sm btn-secondary">+ Saudara</a>
                </div>
            </div>
            <div class="card">
            <h3 class="section-title">Hubungkan dengan yg sudah ada:</h3>
                <form method="post" style="margin-top:15px; border-top:1px solid #eee; padding-top:10px;">
                    <input type="hidden" name="create_relation" value="1">
                    <input type="hidden" name="person_id" value="<?= $currentPerson['id'] ?>">
                    <div class="flex">
                        <div class="flex-1">
                            <select name="related_person_id" required>
                                <option value="">- Pilih Orang -</option>
                                <?php foreach ($otherPersons as $op): if($op['id'] == $currentPerson['id']) continue; ?>
                                    <option value="<?= $op['id'] ?>"><?= htmlspecialchars($op['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex-1">
                            <select name="relation_type" required>
                                <option value="">- Sebagai -</option>
                                <option value="ayah">Ayah</option><option value="ibu">Ibu</option><option value="anak">Anak</option><option value="saudara">Saudara</option><option value="pasangan">Pasangan</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:5px;">Simpan</button>
                </form>
            </div>
            <div class="card card-relations">
            <div class="card-relations-header">
                <div>
                    <h2 class="section-title">Relasi Keluarga</h2>
                    <p class="section-subtitle">Klik nama untuk membuka detail anggota.</p>
                </div>
                <span class="badge-relations">
                    <?= count($relations) ?> relasi
                </span>
            </div>
        
            <?php if (empty($relations)): ?>
                <div class="empty-relations">
                    <div class="empty-icon">🤝</div>
                    <p>Belum ada relasi yang tercatat untuk anggota ini.</p>
                </div>
            <?php else: ?>
                <div class="table-relations-wrapper">
                    <table class="relations-table">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Hubungan</th>
                                <th style="text-align:right;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($relations as $r): ?>
                            <tr class="relation-row" 
                                onclick="window.location='?action=bio&id=<?= $r['related_person_id'] ?>&mode=view'">
                                <td>
                                    <div class="relation-person">
                                        <div class="relation-avatar">
                                            <?= strtoupper(mb_substr($r['related_name'], 0, 1)) ?>
                                        </div>
                                        <div class="relation-text">
                                            <a href="?action=bio&id=<?= $r['related_person_id'] ?>&mode=view" 
                                               class="relation-name"
                                               onclick="event.stopPropagation();">
                                                <?= htmlspecialchars($r['related_name']) ?>
                                            </a>
                                            <span class="relation-id">
                                                ID: <?= (int)$r['related_person_id'] ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="relation-type-pill">
                                        <?= ucfirst($r['relation_type']) ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <div class="relation-actions">
                                        
                                        <?php if (!$isViewingOthers): ?>
                                        <a href="?action=bio&id=<?= $currentPerson['id'] ?>&delete_rel=<?= $r['id'] ?>" 
                                           class="btn-chip btn-chip-delete"
                                           onclick="event.stopPropagation(); return confirm('Hapus relasi ini?');">
                                            ✖ Hapus
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        </div>

<?php elseif ($action === 'tree'): ?>
    <div class="card">
        <div class="export-toolbar">
            <div class="export-label">
                <span style="font-size: 1.2rem;">🌳</span> 
                <span style="font-weight:600; color:#374151;">Pohon Keluarga</span>
            </div>
            <?php 
                $fr = isset($_GET['filter_root']) ? '&filter_root='.intval($_GET['filter_root']) : ''; 
            ?>
            <div class="export-actions">
                <a href="?export=excel<?= $fr ?>" class="btn btn-success btn-sm">📊 Excel</a>
                <a href="?export=word<?= $fr ?>" class="btn btn-primary btn-sm">📝 Word</a>
                <a href="?export=pdf<?= $fr ?>" class="btn btn-danger btn-sm" target="_blank">📄 PDF</a>
            </div>
        </div>

        <?php
        $activeTreeId = $_SESSION['current_tree_id'] ?? 0;
        
        // --- CEK APAKAH SUDAH PILIH POHON? ---
        if ($activeTreeId == 0) { 
            // Load SweetAlert (Jaga-jaga jika belum terload)
            echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
            echo '<style>body { font-family: -apple-system, system-ui, sans-serif; background: #f3f4f6; }</style>';
            
            echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    title: 'Pilih Keluarga Besar',
                    text: 'Anda belum memilih keluarga besar. Silakan pilih atau buat baru untuk melihat pohon silsilah.',
                    icon: 'info',
                    iconColor: '#4f46e5',
                    
                    // Tampilan Tombol Modern
                    confirmButtonText: '🔍 Pilih Sekarang',
                    confirmButtonColor: '#4f46e5',
                    buttonsStyling: true,
                    
                    // Pengaturan Latar (Backdrop) yang Bersih
                    backdrop: `
                        rgba(255,255,255, 0.9)
                        left top
                        no-repeat
                    `,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    
                    // Animasi Masuk/Keluar yang Halus
                    showClass: {
                        popup: 'swal2-show',
                        backdrop: 'swal2-backdrop-show',
                        icon: 'swal2-icon-show'
                    },
                    hideClass: {
                        popup: 'swal2-hide',
                        backdrop: 'swal2-backdrop-hide',
                        icon: 'swal2-icon-hide'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Redirect ke Halaman Home
                        window.location.href = '?action=home';
                    }
                });
            });
            </script>"; 
            
            // Hentikan script di sini agar background halaman tidak berantakan
            exit; 
        }

        // --- HITUNG DATA GENERASI ---
        list($personsByGen, $maxGen, $maxHeight, $generationData, $allPersonsData, $parentChildren, $spouses) 
            = fh_compute_generations($mysqli, $activeTreeId);

        // --- LOGIKA REVOLUSIONER: AUTO ROOT FINDER ---
        // Jika user klik foto (focus_id), kita cari leluhur paling atasnya otomatis
        $focusId = isset($_GET['focus_id']) ? intval($_GET['focus_id']) : 0;
        $targetRootId = null;

        if ($focusId > 0 && isset($allPersonsData[$focusId])) {
            // Algoritma: Naik ke atas (Ayah/Ibu) terus menerus sampai mentok
            $curr = $focusId;
            $safety = 0;
            while($safety < 100) { // Safety break agar tidak infinite loop
                // Cari ortu di array relasi memori (lebih cepat drpd query ulang)
                $foundParent = false;
                // Cari di array $parentChildren (Parent -> Child), kita butuh reverse
                // Kita scan manual generationData atau parents logic
                
                // Query Database langsung untuk mencari ortu (paling akurat)
                $resP = $mysqli->query("SELECT related_person_id FROM relations WHERE person_id=$curr AND relation_type IN ('ayah','ibu') LIMIT 1");
                if ($rowP = $resP->fetch_assoc()) {
                    $curr = $rowP['related_person_id']; // Naik ke ortu
                    $foundParent = true;
                }
                
                if (!$foundParent) break; // Sudah paling atas (Root)
                $safety++;
            }
            $targetRootId = $curr; // Ini adalah leluhur tertinggi dari orang yg diklik
        } 
        // Jika tidak ada focus, cek filter manual
        else {
            $targetRootId = isset($_GET['filter_root']) ? intval($_GET['filter_root']) : null;
        }

        // --- SIAPKAN MENU FILTER ---
        $rootCandidates = [];
        foreach ($allPersonsData as $pid => $info) {
            if (($generationData[$pid] ?? 999) == 1) { $rootCandidates[] = $pid; }
        }
        usort($rootCandidates, function($a, $b) use ($allPersonsData) { 
            return strnatcasecmp($allPersonsData[$a]['name'], $allPersonsData[$b]['name']); 
        });

        $menuList = [];
        $processedRoots = [];
        foreach ($rootCandidates as $pid) {
            if (isset($processedRoots[$pid])) continue;
            $pName = $allPersonsData[$pid]['name'];
            $spouseId = null;
            if (!empty($spouses[$pid])) {
                foreach ($spouses[$pid] as $sid => $_) {
                    if (isset($generationData[$sid]) && $generationData[$sid] == 1) {
                        $spouseId = $sid;
                        $processedRoots[$sid] = true;
                        if (($allPersonsData[$sid]['gender']??'') === 'L') { $pName = $allPersonsData[$sid]['name']; }
                    }
                }
            }
            $processedRoots[$pid] = true;
            $visited = [];
            $count = fh_count_descendants([$pid], $spouses, $parentChildren, $visited);
            $mainIdToRender = (($allPersonsData[$pid]['gender']??'') === 'L') ? $pid : ($spouseId ?? $pid);
            
            $menuList[] = ['id' => $mainIdToRender, 'label' => $pName, 'count' => $count];
        }

        // Tentukan siapa yang dirender
        $rootsToRender = [];
        if ($targetRootId) {
            $rootsToRender[] = $targetRootId;
        } else {
            foreach ($menuList as $m) $rootsToRender[] = $m['id'];
        }
        ?>

        <div style="margin-bottom: 20px;">
            <div style="font-size:0.85rem; color:#6b7280; margin-bottom:5px; font-weight:600;">
                Filter Bani / Leluhur:
            </div>
            <div class="pill-menu">
                <a href="?action=tree" class="pill-item <?= ($targetRootId === null) ? 'active' : '' ?>">🌍 Semua</a>
                <?php foreach ($menuList as $menu): ?>
                    <a href="?action=tree&filter_root=<?= $menu['id'] ?>" 
                       class="pill-item <?= ($targetRootId == $menu['id']) ? 'active' : '' ?>">
                       👤 <?= htmlspecialchars($menu['label']) ?> 
                       <span style="font-size:0.75em; opacity:0.8;">(<?= $menu['count'] ?>)</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="tree-container">
            <div class="tree">
                <ul>
                <?php
                    if (empty($rootsToRender)) {
                        echo "<p style='padding:20px;'>Data tidak ditemukan.</p>";
                    } else {
                        $globalProcessed = []; 
                        foreach ($rootsToRender as $rootId) {
                            if (isset($globalProcessed[$rootId])) continue;
                            
                            // RENDERING DENGAN FOCUS ID
                            // Kita kirim $focusId ke parameter terakhir agar function tahu siapa yang harus di-highlight
                            fh_render_tree_web($rootId, $allPersonsData, $spouses, $parentChildren, $focusId);
                            
                            $globalProcessed[$rootId] = true;
                            if (!empty($spouses[$rootId])) {
                                foreach($spouses[$rootId] as $sid => $_) $globalProcessed[$sid] = true;
                            }
                        }
                    }
                ?>
                </ul>
            </div>
        </div>
        
        <p style="text-align:center; color:#9ca3af; font-size:0.8rem; margin-top:10px;">
            <small>Tips: Klik FOTO untuk melihat silsilah lengkap orang tersebut.<br>Klik NAMA untuk melihat biodata detail.</small>
        </p>

    </div>
    <div id="modalAddRelation" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:9999; backdrop-filter:blur(2px);">
        <div style="background:#fff; padding:20px; border-radius:12px; width:90%; max-width:350px; box-shadow:0 10px 25px rgba(0,0,0,0.2); animation: popUp 0.3s;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
                <h3 style="margin:0; font-size:1.1rem;">Tambah Relasi</h3>
                <button onclick="document.getElementById('modalAddRelation').style.display='none'" style="border:none; background:none; font-size:1.2rem; cursor:pointer;">&times;</button>
            </div>
            
            <p style="font-size:0.9rem; color:#6b7280; margin-bottom:15px;">
                Menambahkan keluarga untuk: <br>
                <strong id="rel_target_name" style="color:#4f46e5; font-size:1rem;"></strong>
            </p>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <a href="#" id="btn_add_father" class="btn btn-secondary btn-sm" style="justify-content:center;">👴 Ayah</a>
                <a href="#" id="btn_add_mother" class="btn btn-secondary btn-sm" style="justify-content:center;">👵 Ibu</a>
                <a href="#" id="btn_add_spouse" class="btn btn-success btn-sm" style="justify-content:center;">💍 Pasangan</a>
                <a href="#" id="btn_add_child"  class="btn btn-primary btn-sm" style="justify-content:center;">👶 Anak</a>
                <a href="#" id="btn_add_sibling" class="btn btn-secondary btn-sm" style="justify-content:center; grid-column: span 2;">👥 Saudara</a>
            </div>
        </div>
    </div>

    
    
    
<?php elseif ($action === 'notifications'): ?>
    <div class="card" style="border:none; background:transparent; box-shadow:none; padding:0;">
        <h2 style="margin-bottom:15px;">🔔 Notifikasi Anda</h2>
        
        <?php
        // Ambil notifikasi: Milik User Ini ATAU Broadcast (0)
        $sqlNotif = "SELECT * FROM notifications 
                     WHERE user_id = $myUserId OR user_id = 0 
                     ORDER BY created_at DESC LIMIT 30";
        $resNotif = $mysqli->query($sqlNotif);
        ?>

        <?php if ($resNotif && $resNotif->num_rows > 0): ?>
            <div style="display:flex; flex-direction:column; gap:12px;">
                <?php while ($row = $resNotif->fetch_assoc()): 
                    // Styling icon berdasarkan tipe
                    $icon = 'ℹ️'; $color = '#3b82f6'; $bg = '#eff6ff';
                    if($row['type'] == 'success') { $icon = '🌱'; $color = '#10b981'; $bg = '#ecfdf5'; }
                    if($row['type'] == 'birthday') { $icon = '🎂'; $color = '#f43f5e'; $bg = '#fff1f2'; }
                    if($row['type'] == 'warning') { $icon = '⚠️'; $color = '#f59e0b'; $bg = '#fffbeb'; }
                ?>
                    <div style="background:#fff; padding:15px; border-radius:12px; border-left: 5px solid <?= $color ?>; box-shadow:0 2px 4px rgba(0,0,0,0.05); display:flex; gap:12px;">
                        <div style="font-size:1.5rem; background:<?= $bg ?>; width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <?= $icon ?>
                        </div>
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <strong style="color:#1f2937; font-size:0.95rem;"><?= htmlspecialchars($row['title']) ?></strong>
                            </div>
                            <p style="color:#4b5563; margin:0; font-size:0.9rem; line-height:1.4;">
                                <?= nl2br(htmlspecialchars($row['message'])) ?>
                            </p>
                            <small style="color:#9ca3af; font-size:0.75rem; margin-top:8px; display:block;">
                                <?= date('d M Y, H:i', strtotime($row['created_at'])) ?>
                            </small>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="card" style="text-align:center; padding:50px 20px;">
                <div style="font-size:3rem; margin-bottom:10px; opacity:0.3;">📭</div>
                <h3 style="color:#374151; margin:0;">Belum ada notifikasi</h3>
                <p style="color:#6b7280; font-size:0.9rem;">Info keluarga dan pengumuman akan muncul di sini.</p>
            </div>
        <?php endif; ?>
        
        <?php if ($resNotif && $resNotif->num_rows > 0): ?>
            <form method="post" style="text-align:center; margin-top:20px;">
                <input type="hidden" name="clear_notif" value="1">
                <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Hapus semua notifikasi?')">Bersihkan Riwayat</button>
            </form>
            <?php
            if(isset($_POST['clear_notif'])) {
                // Hapus hanya milik user (jangan hapus broadcast ID 0 karena milik semua orang)
                $mysqli->query("DELETE FROM notifications WHERE user_id = $myUserId");
                echo "<script>window.location.href='?action=notifications';</script>";
            }
            ?>
        <?php endif; ?>
    </div>


    <?php elseif ($action === 'settings'): ?>
    <div class="card" style="padding:0; overflow:hidden;">
        <div style="background: linear-gradient(135deg, #4f46e5, #6366f1); color:#fff; padding:30px 20px; text-align:center;">
            <div style="width:80px; height:80px; background:#fff; color:#4f46e5; font-size:2rem; font-weight:bold; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 10px;">
                <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>
            </div>
            <h2 style="margin:0; font-size:1.2rem; color:#fff;"><?= htmlspecialchars($_SESSION['user_name']) ?></h2>
            <p style="margin:5px 0 0; opacity:0.8; font-size:0.9rem; color:#e0e7ff;"><?= htmlspecialchars($_SESSION['user_email']) ?></p>
        </div>

        <div class="menu-list">
            <?php if ($isAdmin): ?>
            <a href="?action=admin_users" style="color:#4f46e5;">
                <span class="menu-icon">👑</span> Dashboard Admin
            </a>
            <?php endif; ?>

            <a href="?action=support">
                <span class="menu-icon">📩</span> Pusat Bantuan / Feedback
            </a>
            
            <div style="padding:15px; border-bottom:1px solid #f3f4f6;">
                <h3 style="font-size:1rem; margin-top:0; margin-bottom:10px;">Pengaturan Akses Admin Per Keluarga</h3>
                <p style="font-size:0.85rem; color:#6b7280; margin-bottom:15px;">
                    Izinkan Admin sistem untuk melihat data di pohon keluarga tertentu (untuk tujuan dukungan atau troubleshooting).
                </p>

                <?php
                // Ambil semua pohon milik user ini + status allow_admin_view (Asumsi kolom sudah ditambahkan)
                $trees = $mysqli->query("SELECT id, name, allow_admin_view FROM family_trees WHERE user_id = $myUserId ORDER BY name ASC");
                if ($trees->num_rows > 0):
                ?>
                    <div style="border:1px solid #e5e7eb; border-radius:10px; padding:15px; background:#fff;">
                        <?php while ($t = $trees->fetch_assoc()): ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid #eee;">
                                <span style="font-weight:600; color:#1f2937;"><?= htmlspecialchars($t['name']) ?></span>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="toggle_tree_admin_view" value="1">
                                    <input type="hidden" name="tree_id" value="<?= $t['id'] ?>">
                                    <?php if ($t['allow_admin_view'] ?? 0): ?>
                                        <input type="hidden" name="new_status" value="0">
                                        <button type="submit" class="btn btn-sm" 
                                                style="background:#dc2626; color:#fff; padding:6px 10px; font-size:0.75rem; border-radius:99px;"
                                                onclick="return confirm('Yakin kunci akses Admin untuk Keluarga <?= htmlspecialchars($t['name']) ?>?');">
                                            🔒 Diizinkan
                                        </button>
                                    <?php else: ?>
                                        <input type="hidden" name="new_status" value="1">
                                        <button type="submit" class="btn btn-sm" 
                                                style="background:#10b981; color:#fff; padding:6px 10px; font-size:0.75rem; border-radius:99px;"
                                                onclick="return confirm('Yakin izinkan akses Admin untuk Keluarga <?= htmlspecialchars($t['name']) ?>?');">
                                            ❌ Terkunci
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        <?php endwhile; ?>
                        <p style="font-size:0.75rem; color:#9ca3af; margin:10px 0 0; text-align:center;">
                            Catatan: Hanya pohon keluarga yang Anda buat sendiri yang tampil di sini.
                        </p>
                    </div>
                <?php else: ?>
                    <p style="color:#9ca3af; font-style:italic;">Anda belum memiliki keluarga besar yang dibuat. Tidak ada yang bisa dibagikan ke admin.</p>
                <?php endif; ?>
            </div>

            <a href="?action=logout" onclick="return confirm('Keluar dari aplikasi?')" style="color:#ef4444;">
                <span class="menu-icon">🚪</span> Keluar Aplikasi
            </a>
            <a href="?action=about">
                <span class="menu-icon">ℹ️</span> Tentang Aplikasi
            </a>
            
            <a href="?action=privacy">
                <span class="menu-icon">🛡️</span> Kebijakan Privasi
            </a>
        </div>
    </div>

<?php elseif ($action === 'support'): ?>
    <div class="card">
        <h2>📩 Pusat Bantuan & Feedback</h2>
        
        <?php
        // Proses Kirim Tiket
        if (isset($_POST['send_ticket'])) {
            $subj = trim($_POST['subject']);
            $msg  = trim($_POST['message']);
            if ($subj && $msg) {
                $stmt = $mysqli->prepare("INSERT INTO support_tickets (user_id, subject, message) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $myUserId, $subj, $msg);
                $stmt->execute();
                echo "<div class='alert alert-success'>Pesan terkirim! Admin akan segera membalas.</div>";
            }
        }
        ?>

        <form method="post" style="margin-bottom:30px;">
            <input type="hidden" name="send_ticket" value="1">
            <label>Judul Masalah / Permintaan Fitur</label>
            <input type="text" name="subject" required placeholder="Misal: Data error atau Request fitur PDF">
            <label>Pesan Detail</label>
            <textarea name="message" required placeholder="Jelaskan masalah atau ide Anda..."></textarea>
            <button type="submit" class="btn btn-primary" style="margin-top:10px;">Kirim Pesan</button>
        </form>

        <h3 class="section-title">Riwayat Pesan Anda</h3>
        <?php
        $tickets = $mysqli->query("SELECT * FROM support_tickets WHERE user_id=$myUserId ORDER BY created_at DESC");
        if ($tickets->num_rows > 0):
            while ($t = $tickets->fetch_assoc()):
        ?>
            <div style="border:1px solid #e5e7eb; border-radius:8px; padding:15px; margin-bottom:10px; background:#fff;">
                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                    <strong><?= htmlspecialchars($t['subject']) ?></strong>
                    <span style="font-size:0.8rem; color:#9ca3af;"><?= date('d M Y H:i', strtotime($t['created_at'])) ?></span>
                </div>
                <p style="color:#4b5563; margin-bottom:10px;"><?= nl2br(htmlspecialchars($t['message'])) ?></p>
                
                <?php if ($t['admin_reply']): ?>
                    <div style="background:#ecfdf5; padding:10px; border-radius:6px; border-left:4px solid #10b981; font-size:0.9rem;">
                        <strong>👑 Balasan Admin:</strong><br>
                        <?= nl2br(htmlspecialchars($t['admin_reply'])) ?>
                    </div>
                <?php else: ?>
                    <div style="font-size:0.8rem; color:#d97706; background:#fffbeb; display:inline-block; padding:2px 8px; border-radius:99px;">⏳ Menunggu balasan</div>
                <?php endif; ?>
            </div>
        <?php endwhile; else: ?>
            <p style="color:#6b7280; text-align:center;">Belum ada riwayat pesan.</p>
        <?php endif; ?>
    </div>

-
<?php
// --- HALAMAN TENTANG KAMI (ABOUT) ---
elseif ($action === 'about'): ?>
    <div class="card">
        <div style="text-align:center; margin-bottom:20px;">
            <div style="width:60px; height:60px; background:#4f46e5; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 10px; color:#fff; font-size:1.5rem;">
                👨‍👩‍👧‍👦
            </div>
            <h2 style="margin:0;">Tentang FamilyHood</h2>
            <p style="color:#6b7280; font-size:0.9rem;">Menyambung Tali Silaturahmi Digital</p>
        </div>
        
        <p style="line-height:1.6; color:#374151;">
            <strong>FamilyHood</strong> adalah aplikasi silsilah keluarga digital yang dirancang untuk membantu Anda mencatat, menyimpan, dan melestarikan sejarah keluarga besar Anda. Kami percaya bahwa setiap keluarga memiliki cerita berharga yang harus dijaga agar bisa dikenang oleh generasi mendatang.
        </p>
        
        <h3 class="section-title" style="margin-top:20px;">Misi Kami</h3>
        <ul style="padding-left:20px; line-height:1.6; color:#374151;">
            <li>🌍 <strong>Menghubungkan Generasi:</strong> Memudahkan anak cucu mengenal leluhur mereka.</li>
            <li>🔒 <strong>Menjaga Privasi:</strong> Data keluarga Anda aman dan hanya bisa diakses oleh Anda (dan admin sistem jika diizinkan).</li>
            <li>📊 <strong>Visualisasi Mudah:</strong> Melihat hubungan keluarga dalam bentuk pohon visual yang interaktif.</li>
        </ul>

        <h3 class="section-title" style="margin-top:20px;">Kontak Kami</h3>
        <div style="background:#f0f9ff; padding:15px; border-radius:10px; border:1px solid #bae6fd; margin-bottom:20px;">
            <p style="margin:0 0 8px; font-weight:600; color:#0c4a6e;">Informasi Pengembang/Admin:</p>
            <ul style="list-style:none; padding:0; margin:0; font-size:0.9rem;">
                <li style="margin-bottom:5px;">📱 HP: <a href="tel:+6281234567890" style="color:#1d4ed8; text-decoration:none;">+62 85743399595</a></li>
                <li style="margin-bottom:5px;">📧 Email: <a href="mailto:admin@familyhood.com" style="color:#1d4ed8; text-decoration:none;">admin@familyhood.com</a></li>
                <li>📸 Instagram: <a href="https://instagram.com/zainul.hakim" target="_blank" style="color:#1d4ed8; text-decoration:none;">@zainul.hakim</a></li>
            </ul>
        </div>
        
        <h3 class="section-title" style="margin-top:20px;">Fitur Unggulan</h3>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px;">
            <div style="background:#f9fafb; padding:10px; border-radius:8px; font-size:0.85rem;">
                <strong>🌳 Pohon Dinamis</strong><br>Otomatis menyusun bagan keturunan.
            </div>
            <div style="background:#f9fafb; padding:10px; border-radius:8px; font-size:0.85rem;">
                <strong>📄 Export Data</strong><br>Unduh ke PDF, Word, atau Excel dengan mudah.
            </div>
            <div style="background:#f9fafb; padding:10px; border-radius:8px; font-size:0.85rem;">
                <strong>🎂 Pengingat Ultah</strong><br>Notifikasi ulang tahun anggota keluarga.
            </div>
            <div style="background:#f9fafb; padding:10px; border-radius:8px; font-size:0.85rem;">
                <strong>🖼️ Galeri Foto</strong><br>Simpan foto kenangan setiap anggota.
            </div>
        </div>

        <div style="margin-top:30px; text-align:center; border-top:1px solid #eee; padding-top:20px;">
            <p style="font-size:0.8rem; color:#9ca3af;">
                Versi Aplikasi: 1.0.0<br>
                Dibuat dengan ❤️ oleh Tim Pengembang.
            </p>
            <a href="?action=settings" class="btn btn-secondary btn-sm">← Kembali ke Pengaturan</a>
        </div>
    </div>
<?
// --- HALAMAN KEBIJAKAN PRIVASI (PRIVACY POLICY) ---
elseif ($action === 'privacy'): ?>
    <div class="card">
        <h2 style="margin-bottom:10px;">🔒 Kebijakan Privasi</h2>
        <p style="font-size:0.85rem; color:#6b7280; margin-bottom:20px;">Terakhir diperbarui: <?= date('d M Y') ?></p>
        
        <div style="font-size:0.9rem; line-height:1.6; color:#374151;">
            <p>Di <strong>FamilyHood</strong>, kami sangat menghargai privasi Anda. Dokumen ini menjelaskan bagaimana kami mengumpulkan, menggunakan, dan melindungi data pribadi keluarga Anda.</p>
            
            <h3 style="font-size:1rem; margin-top:15px; color:#1f2937;">1. Data yang Kami Kumpulkan</h3>
            <p>Untuk menjalankan fungsi silsilah keluarga, kami menyimpan data berikut yang Anda inputkan secara sukarela:</p>
            <ul style="padding-left:20px;">
                <li><strong>Data Akun:</strong> Nama, Email, dan Password (dienkripsi).</li>
                <li><strong>Data Anggota Keluarga:</strong> Nama, Jenis Kelamin, Tanggal Lahir, Tempat Lahir, Status Hidup, dan Catatan Tambahan.</li>
                <li><strong>Media:</strong> Foto profil anggota keluarga yang Anda unggah.</li>
            </ul>

            <h3 style="font-size:1rem; margin-top:15px; color:#1f2937;">2. Penggunaan Data</h3>
            <p>Data Anda digunakan semata-mata untuk:</p>
            <ul style="padding-left:20px;">
                <li>Menampilkan visualisasi pohon keluarga.</li>
                <li>Mengirimkan notifikasi (seperti pengingat ulang tahun).</li>
                <li>Membuat laporan (Export PDF/Word) atas permintaan Anda.</li>
            </ul>
            <p><strong>Kami TIDAK akan pernah menjual data keluarga Anda kepada pihak ketiga.</strong></p>

            <h3 style="font-size:1rem; margin-top:15px; color:#1f2937;">3. Keamanan & Akses</h3>
            <p>
                Akun Anda dilindungi kata sandi. Secara default, data pohon keluarga Anda bersifat <strong>Pribadi</strong>. 
                Hanya Admin sistem yang memiliki akses teknis untuk pemeliharaan, atau jika Anda secara eksplisit mengaktifkan fitur "Izinkan Admin Lihat Data" di menu Pengaturan.
            </p>

            <h3 style="font-size:1rem; margin-top:15px; color:#1f2937;">4. Hak Anda</h3>
            <p>Anda memiliki kendali penuh untuk:</p>
            <ul style="padding-left:20px;">
                <li>Mengakses dan mengubah data kapan saja.</li>
                <li>Menghapus anggota keluarga atau menghapus akun Anda secara permanen.</li>
                <li>Mengunduh salinan data Anda.</li>
            </ul>

            <div style="margin-top:20px; background:#eff6ff; padding:10px; border-radius:8px; border-left:4px solid #3b82f6;">
                <strong>Hubungi Kami</strong><br>
                Jika ada pertanyaan mengenai privasi, Anda dapat menghubungi kami:<br>
                <ul style="list-style:disc; padding-left:20px; margin:5px 0 0; font-size:0.9rem;">
                    <li>📱 HP: <a href="tel:+6281234567890" style="color:#1d4ed8; text-decoration:none;">+62 85743399595</a></li>
                    <li>📧 Email: <a href="mailto:admin@familyhood.com" style="color:#1d4ed8; text-decoration:none;">admin@familyhood.com</a></li>
                    <li>📸 Instagram: <a href="https://instagram.com/zainul.hakim" target="_blank" style="color:#1d4ed8; text-decoration:none;">@zainul.hakim</a></li>
                </ul>
            </div>
        </div>
        
        <div style="margin-top:20px; text-align:center;">
            <a href="?action=settings" class="btn btn-secondary btn-sm">← Kembali ke Pengaturan</a>
        </div>
    </div>

<?php elseif ($action === 'admin_users' && $isAdmin): ?>
    <div class="card">
        <h2>👑 Dashboard Admin</h2>
        
        <h3 class="section-title">Daftar Pengguna</h3>
        <div style="overflow-x:auto;">
            <table style="min-width: 700px;">
                <thead><tr><th>ID</th><th>Nama</th><th>Email</th><th>Waktu Bergabung</th><th>Akses Data</th></tr></thead>
                <tbody>
                <?php
                // Ambil data dari tabel users. Kolom allow_admin_view di users sekarang diabaikan.
                $users = $mysqli->query("SELECT id, name, email, is_active FROM users WHERE role != 'admin' ORDER BY created_at DESC");
                while ($u = $users->fetch_assoc()):
                ?>
                    <tr>
                        <td>#<?= $u['id'] ?></td>
                        <td><?= htmlspecialchars($u['name']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><?= date('d M Y H:i', strtotime($u['created_at'])) ?></td>

                        <td>
                            <?php 
                            // Hitung jumlah pohon yang diizinkan admin untuk user ini
                            $countStmt = $mysqli->prepare("SELECT COUNT(*) FROM family_trees WHERE user_id = ? AND allow_admin_view = 1");
                            $countStmt->bind_param("i", $u['id']);
                            $countStmt->execute();
                            $countStmt->bind_result($viewableTreesCount);
                            $countStmt->fetch();
                            $countStmt->close();
                            ?>
                            
                            <?php if ($viewableTreesCount > 0): ?>
                                <button type="button" 
                                        onclick="showAdminViewModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>')"
                                        class="btn btn-sm btn-primary"
                                        style="background:#4f46e5; color:#fff; padding:6px 10px; font-size:0.75rem;">
                                    ✅ <?= $viewableTreesCount ?> Keluarga Diizinkan
                                </button>
                            <?php else: ?>
                                <span style="color:#9ca3af; font-size:0.85rem;">🔒 Private (0 Keluarga)</span>
                            <?php endif; ?>
                        </td>

                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top:40px; padding-top:20px; border-top:2px dashed #e5e7eb;">
            <h3 class="section-title">📢 Kirim Pengumuman / Notifikasi</h3>
            
            <?php
            if (isset($_POST['send_broadcast'])) {
                $targetUser = intval($_POST['broadcast_target']);
                $bTitle = trim($_POST['broadcast_title']);
                $bMsg = trim($_POST['broadcast_message']);
                
                if ($bTitle && $bMsg) {
                    // Fungsi helper kita tadi
                    fh_send_notification($mysqli, $targetUser, $bTitle, $bMsg, 'info');
                    echo "<div class='alert alert-success'>Notifikasi berhasil dikirim!</div>";
                }
            }
            ?>
            
            <form method="post" style="background:#f9fafb; padding:15px; border-radius:10px;">
                <input type="hidden" name="send_broadcast" value="1">
                
                <label>Tujuan:</label>
                <select name="broadcast_target" required>
                    <option value="0">📢 SEMUA USER (Broadcast)</option>
                    <?php 
                    // Ambil list user lagi untuk dropdown
                    $usersList = $mysqli->query("SELECT id, name FROM users WHERE role != 'admin'");
                    while($u = $usersList->fetch_assoc()) {
                        echo "<option value='".$u['id']."'>👤 ".$u['name']."</option>";
                    }
                    ?>
                </select>
                
                <label>Judul:</label>
                <input type="text" name="broadcast_title" placeholder="Contoh: Update Sistem" required>
                
                <label>Pesan:</label>
                <textarea name="broadcast_message" placeholder="Tulis pesan..." required></textarea>
                
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">Kirim Notifikasi</button>
            </form>
        </div>

        <h3 class="section-title" style="margin-top:30px;">Tiket Bantuan Masuk</h3>
        
        <?php
        // Proses Balas Tiket
        if (isset($_POST['reply_ticket'])) {
            $tid = intval($_POST['ticket_id']);
            $reply = trim($_POST['reply']);
            if ($reply) {
                $stmt = $mysqli->prepare("UPDATE support_tickets SET admin_reply=?, status='answered' WHERE id=?");
                $stmt->bind_param("si", $reply, $tid);
                $stmt->execute();
                echo "<div class='alert alert-success'>Balasan terkirim.</div>";
            }
        }
        
        $allTickets = $mysqli->query("SELECT t.*, u.name as user_name FROM support_tickets t JOIN users u ON t.user_id = u.id ORDER BY t.status ASC, t.created_at DESC");
        ?>
        
        <?php if ($allTickets->num_rows > 0): ?>
            <?php while ($at = $allTickets->fetch_assoc()): ?>
                <div style="border:1px solid <?= $at['status']=='open' ? '#fcd34d' : '#e5e7eb' ?>; border-radius:8px; padding:15px; margin-bottom:15px; background:<?= $at['status']=='open' ? '#fffbeb' : '#fff' ?>;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                        <strong><?= htmlspecialchars($at['user_name']) ?>: <?= htmlspecialchars($at['subject']) ?></strong>
                        <span style="font-size:0.8rem;"><?= date('d M H:i', strtotime($at['created_at'])) ?></span>
                    </div>
                    <p style="margin-bottom:10px; font-size:0.9rem;"><?= nl2br(htmlspecialchars($at['message'])) ?></p>
                    
                    <?php if (empty($at['admin_reply'])): ?>
                        <form method="post" style="display:flex; gap:5px;">
                            <input type="hidden" name="ticket_id" value="<?= $at['id'] ?>">
                            <input type="text" name="reply" placeholder="Tulis balasan..." required style="flex:1;">
                            <button type="submit" name="reply_ticket" class="btn btn-sm btn-success">Kirim Balasan</button>
                        </form>
                    <?php else: ?>
                        <div style="border-top:1px dashed #ccc; padding-top:5px; font-size:0.85rem; color:#059669;">
                            <strong>Balasan Anda:</strong> <?= htmlspecialchars($at['admin_reply']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p style="color:#6b7280;">Tidak ada tiket bantuan.</p>
        <?php endif; ?>
    </div>

<?php endif; ?>

</div>

<nav class="bottom-nav">
    <a href="?action=home" class="nav-item <?= ($action === 'home') ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"></path></svg>
        Daftar
    </a>

    <a href="?action=tree" class="nav-item <?= ($action === 'tree') ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M12 3v18m-7-6l7-7 7 7m-7 7v-4"></path><circle cx="12" cy="5" r="2"></circle></svg>
        Pohon
    </a>

    <a href="?action=add_person" class="nav-item add-btn">
        <div>
            <svg viewBox="0 0 24 24" style="width:30px; height:30px;"><path d="M12 5v14m-7-7h14"></path></svg>
        </div>
    </a>
    
    <a href="?action=notifications" class="nav-item <?= ($action === 'notifications') ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
        Info
    </a>

    <a href="?action=settings" class="nav-item <?= ($action === 'settings' || $action === 'support' || $action === 'admin_users') ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
        Akun
    </a>
</nav>


<?php if ($isAdmin): ?>
<div id="modalAdminView" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:9999; backdrop-filter:blur(2px);">
    <div style="background:#fff; padding:25px; border-radius:12px; width:90%; max-width:500px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #f3f4f6; padding-bottom:10px;">
            <h3 style="margin:0;">Akses Admin untuk <span id="admin_view_target_name" style="color:#4f46e5;"></span></h3>
            <button onclick="document.getElementById('modalAdminView').style.display='none'" style="border:none; background:none; font-size:1.5rem; cursor:pointer; color:#6b7280;">&times;</button>
        </div>
        
        <p style="font-size:0.9rem; color:#6b7280;">Berikut adalah daftar pohon keluarga yang **diizinkan** oleh user untuk dilihat Admin:</p>
        
        <ul id="admin_view_tree_list" style="list-style:none; padding:0; margin:15px 0 0; max-height:300px; overflow-y:auto; border:1px solid #eee; border-radius:8px; background:#fafafa;">
            </ul>
    </div>
</div>
<?php endif; ?>


<script>
// ==========================================
// 1. FUNGSI GLOBAL (WAJIB ADA DI SINI)
// ==========================================

// Fungsi Buka Modal Edit
function editTree(id, name) {
    document.getElementById('edit_tree_id').value = id;
    document.getElementById('edit_tree_name').value = name;
    document.getElementById('modalEdit').style.display = 'flex';
}

// Fungsi Buka Modal Delete
function deleteTree(id, name) {
    const modal = document.getElementById('modalDelete');
    if (!modal) return; 

    document.getElementById('del_tree_id').value = id;
    document.getElementById('del_tree_name_disp').innerText = name;
    
    const checkbox = document.getElementById('confirm_delete_check');
    if (checkbox) {
        checkbox.checked = false;
        toggleDeleteBtn(checkbox);
    }
    
    modal.style.display = 'flex';
}

// Fungsi Buka Modal Tambah Relasi (Tree)
function openRelationModal(personId, personName) {
    const modal = document.getElementById('modalAddRelation');
    if (!modal) {
        console.error("Modal Add Relation tidak ditemukan di DOM.");
        return; 
    }

    // Set Nama di Modal
    const nameTarget = document.getElementById('rel_target_name');
    if(nameTarget) nameTarget.innerText = personName;
    
    // Base URL
    const baseUrl = '?action=add_person&from_id=' + personId;
    
    // Update Link tombol (Safety check elements exist)
    if(document.getElementById('btn_add_father')) document.getElementById('btn_add_father').href  = baseUrl + '&relation_type=ayah';
    if(document.getElementById('btn_add_mother')) document.getElementById('btn_add_mother').href  = baseUrl + '&relation_type=ibu';
    if(document.getElementById('btn_add_spouse')) document.getElementById('btn_add_spouse').href  = baseUrl + '&relation_type=pasangan';
    if(document.getElementById('btn_add_child')) document.getElementById('btn_add_child').href   = baseUrl + '&relation_type=anak';
    if(document.getElementById('btn_add_sibling')) document.getElementById('btn_add_sibling').href = baseUrl + '&relation_type=saudara';
    
    // Tampilkan Modal
    modal.style.display = 'flex';
}

// Fungsi Toggle Tombol Delete
function toggleDeleteBtn(checkbox) {
    const btn = document.getElementById('btn_delete_submit');
    if (checkbox.checked) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    } else {
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
    }
}

// ==========================================
// 2. LOGIKA UTAMA (NAVIGASI & AJAX)
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    const loader = document.getElementById('page-loader');
    const mainContent = document.getElementById('main-content');

    const showLoader = () => loader && loader.classList.add('active');
    const hideLoader = () => loader && setTimeout(() => loader.classList.remove('active'), 300); 

    async function loadPage(url, pushState = true) {
        showLoader();
        try {
            const response = await fetch(url);
            // Redirect jika session habis
            if (response.url.includes('login.php')) {
                window.location.href = 'login.php';
                return;
            }

            const text = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(text, 'text/html');
            const newContent = doc.getElementById('main-content');
            const newTitle = doc.title;

            if (newContent) {
                mainContent.innerHTML = newContent.innerHTML;
                document.title = newTitle;
                
                mainContent.classList.remove('fade-in');
                void mainContent.offsetWidth; 
                mainContent.classList.add('fade-in');

                if (pushState) history.pushState({ url: url }, newTitle, url);
                updateActiveNav(url);
                window.scrollTo(0, 0);
            }
        } catch (error) {
            console.error('Gagal memuat:', error);
        } finally {
            hideLoader();
        }
    }

    function updateActiveNav(url) {
        const navLinks = document.querySelectorAll('.bottom-nav .nav-item, .desktop-nav .d-link');
        navLinks.forEach(link => link.classList.remove('active'));
        navLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (href && url.includes(href)) link.classList.add('active');
        });
    }

    document.addEventListener('click', (e) => {
        // Cegah klik pada tombol modal atau fungsi JS
        if (e.target.onclick) return;

        const link = e.target.closest('a');
        if (link && link.href && 
            link.href.includes(window.location.hostname) && 
            !link.getAttribute('target') && 
            !link.getAttribute('onclick') && 
            !link.href.includes('logout') && 
            !link.href.includes('reset_tree') && 
            !link.href.includes('action=tree') && // <--- TAMBAHKAN BARIS INI
            !link.href.includes('#') &&
            !link.href.includes('export=')) { 
            e.preventDefault(); 
            loadPage(link.href);
        }
    });

    window.addEventListener('popstate', (e) => {
        if (e.state && e.state.url) loadPage(e.state.url, false);
        else loadPage(window.location.href, false);
    });
});



// --- LOGIKA SWEETALERT POPUP HASIL SHARE ---
<?php if (isset($shareStatus)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: '<?= $shareStatus['icon'] ?>',
            title: '<?= $shareStatus['title'] ?>',
            text: '<?= $shareStatus['text'] ?>',
            confirmButtonColor: '#4f46e5'
        });
    });
<?php endif; ?>

// --- FUNGSI GLOBAL LAINNYA ---
function editTree(id, name) {
    document.getElementById('edit_tree_id').value = id;
    document.getElementById('edit_tree_name').value = name;
    document.getElementById('modalEdit').style.display = 'flex';
}

function deleteTree(id, name) {
    const modal = document.getElementById('modalDelete');
    if (!modal) return; 
    document.getElementById('del_tree_id').value = id;
    document.getElementById('del_tree_name_disp').innerText = name;
    modal.style.display = 'flex';
}

function toggleDeleteBtn(checkbox) {
    const btn = document.getElementById('btn_delete_submit');
    if (checkbox.checked) {
        btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
    } else {
        btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
    }
}

// --- FUNGSI SHARE DENGAN LOAD LIST AJAX (UPDATED) ---
function openShareModal(id, name) {
    // 1. Set Data Form
    document.getElementById('share_tree_id').value = id;
    document.getElementById('share_tree_name_disp').innerText = name;
    document.getElementById('modalShare').style.display = 'flex';
    
    // 2. Load List Kolaborator via AJAX
    const listContainer = document.getElementById('collab_list_container');
    listContainer.innerHTML = '<li style="text-align:center; color:#999; padding:10px;">Sedang memuat...</li>';
    
    fetch('?action=get_collaborators&tree_id=' + id)
        .then(response => response.json())
        .then(data => {
            listContainer.innerHTML = ''; // Kosongkan
            
            if (data.length === 0) {
                listContainer.innerHTML = '<li style="text-align:center; color:#9ca3af; font-size:0.85rem; padding:10px;">Belum ada kolaborator.</li>';
            } else {
                data.forEach(user => {
                    const li = document.createElement('li');
                    li.style.display = 'flex';
                    li.style.alignItems = 'center';
                    li.style.justifyContent = 'space-between';
                    li.style.padding = '8px 0';
                    li.style.borderBottom = '1px solid #f9fafb';
                    
                    li.innerHTML = `
                        <div style="display:flex; align-items:center; gap:10px;">
                            <div style="width:30px; height:30px; background:#e0e7ff; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#4f46e5; font-weight:bold; font-size:0.8rem;">
                                ${user.initial || user.name.charAt(0)}
                            </div>
                            <div>
                                <div style="font-size:0.9rem; font-weight:600; color:#374151;">${user.name}</div>
                                <div style="font-size:0.75rem; color:#9ca3af;">${user.email}</div>
                            </div>
                        </div>
                        <form method="post" onsubmit="return confirm('Hapus akses untuk ${user.name}?');" style="margin:0;">
                            <input type="hidden" name="remove_collab" value="1">
                            <input type="hidden" name="tree_id" value="${id}">
                            <input type="hidden" name="collab_user_id" value="${user.user_id}">
                            <button type="submit" style="background:none; border:none; color:#ef4444; font-size:0.8rem; cursor:pointer;">Hapus</button>
                        </form>
                    `;
                    listContainer.appendChild(li);
                });
            }
        })
        .catch(err => {
                console.error('Error get_collaborators:', err);
                listContainer.innerHTML = '<li style="color:red; text-align:center;">Gagal memuat data kolaborator.</li>';
            });
}

// TAMBAHKAN LOGIKA AJAX BARU UNTUK MODAL ADMIN VIEW
function showAdminViewModal(userId, userName) {
    const modal = document.getElementById('modalAdminView');
    const listContainer = document.getElementById('admin_view_tree_list');
    
    if (!modal) return;
    
    document.getElementById('admin_view_target_name').innerText = userName;
    listContainer.innerHTML = '<li style="text-align:center; color:#9ca3af; padding:15px;">Memuat data pohon...</li>';
    modal.style.display = 'flex';
    
    // AJAX untuk mengambil daftar tree yang diizinkan
    fetch('?action=get_viewable_trees&user_id=' + userId)
        .then(response => {
            // Periksa apakah ada pengalihan ke login
            if (response.url.includes('login.php')) {
                window.location.href = 'login.php';
                return;
            }
            return response.json();
        })
        .then(data => {
            listContainer.innerHTML = ''; 
            if (data.length === 0) {
                listContainer.innerHTML = '<li style="text-align:center; color:#9ca3af; padding:15px;">Tidak ada pohon keluarga yang diizinkan.</li>';
            } else {
                data.forEach(tree => {
                    const li = document.createElement('li');
                    li.style.padding = '10px 15px';
                    li.style.borderBottom = '1px solid #eee';
                    
                    li.innerHTML = `
                        <div style="font-weight:600; color:#1f2937;">🌳 ${tree.name}</div>
                        <div style="font-size:0.8rem; color:#6b7280;">ID: ${tree.id}</div>
                        <a href="?action=home&view_user_id=${userId}&view_tree_id=${tree.id}" 
                           target="_blank" 
                           class="btn btn-sm btn-primary" 
                           style="margin-top:5px; font-size:0.75rem; background:#10b981;">
                            👁️ Lihat Pohon Ini
                        </a>
                    `;
                    listContainer.appendChild(li);
                });
            }
        })
        .catch(err => {
            console.error('Error fetching viewable trees:', err);
            listContainer.innerHTML = '<li style="color:red; text-align:center; padding:15px;">Gagal memuat data.</li>';
        });
}


</script>

</body>
</html>