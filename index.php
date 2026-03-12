<?php
session_start();
$db = new SQLite3('school_sms.db');
$db->exec("PRAGMA foreign_keys = ON;");

// Create Tables
$db->exec("
CREATE TABLE IF NOT EXISTS classes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    section TEXT NOT NULL,
    teacher TEXT,
    monthly_fee REAL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(name, section)
);

CREATE TABLE IF NOT EXISTS students (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    roll_no TEXT,
    name TEXT NOT NULL,
    father_name TEXT,
    phone TEXT,
    address TEXT,
    dob TEXT,
    gender TEXT,
    class_id INTEGER,
    admission_date TEXT,
    status TEXT DEFAULT 'active',
    photo TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(class_id) REFERENCES classes(id)
);

CREATE TABLE IF NOT EXISTS fees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    month TEXT NOT NULL,
    year INTEGER NOT NULL,
    amount REAL NOT NULL,
    paid_date TEXT,
    status TEXT DEFAULT 'unpaid',
    remarks TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(student_id) REFERENCES students(id)
);

CREATE TABLE IF NOT EXISTS attendance (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    class_id INTEGER NOT NULL,
    date TEXT NOT NULL,
    status TEXT DEFAULT 'present',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(student_id, date),
    FOREIGN KEY(student_id) REFERENCES students(id),
    FOREIGN KEY(class_id) REFERENCES classes(id)
);

CREATE TABLE IF NOT EXISTS promotions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    from_class_id INTEGER,
    to_class_id INTEGER,
    promoted_date TEXT,
    academic_year TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// Seed default classes if empty
$check = $db->querySingle("SELECT COUNT(*) FROM classes");
if ($check == 0) {
    $classes = [
        ['Nursery','A','Mr. Ahmed',1500],
        ['Nursery','B','Ms. Sara',1500],
        ['KG','A','Mr. Raza',1800],
        ['KG','B','Ms. Nadia',1800],
        ['Class 1','A','Mr. Hassan',2000],
        ['Class 1','B','Ms. Zara',2000],
        ['Class 2','A','Mr. Bilal',2200],
        ['Class 3','A','Ms. Hina',2200],
        ['Class 4','A','Mr. Usman',2500],
        ['Class 5','A','Ms. Fatima',2500],
    ];
    $stmt = $db->prepare("INSERT INTO classes (name,section,teacher,monthly_fee) VALUES (?,?,?,?)");
    foreach($classes as $c){
        $stmt->bindValue(1,$c[0]);
        $stmt->bindValue(2,$c[1]);
        $stmt->bindValue(3,$c[2]);
        $stmt->bindValue(4,$c[3]);
        $stmt->execute();
        $stmt->reset();
    }
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function getClasses($db){ return $db->query("SELECT * FROM classes ORDER BY name,section"); }
function getClassById($db,$id){ return $db->querySingle("SELECT * FROM classes WHERE id=$id",true); }
function getNextClass($db,$classId){
    $cur = getClassById($db,$classId);
    $all = [];
    $res = $db->query("SELECT * FROM classes ORDER BY name,section");
    while($r=$res->fetchArray(SQLITE3_ASSOC)) $all[]=$r;
    foreach($all as $i=>$c){
        if($c['id']==$classId && isset($all[$i+1])) return $all[$i+1];
    }
    return null;
}
function getStudentsByClass($db,$classId){
    return $db->query("SELECT * FROM students WHERE class_id=$classId AND status='active' ORDER BY roll_no,name");
}
function getFeeStatus($db,$studentId,$month,$year){
    return $db->querySingle("SELECT * FROM fees WHERE student_id=$studentId AND month='$month' AND year=$year",true);
}
function currentMonth(){ return date('F'); }
function currentYear(){ return date('Y'); }
function months(){ return ['January','February','March','April','May','June','July','August','September','October','November','December']; }
function getAttendance($db,$studentId,$date){
    return $db->querySingle("SELECT * FROM attendance WHERE student_id=$studentId AND date='$date'",true);
}
function getAttendanceSummary($db,$studentId,$month,$year){
    $monthNum = date('m', strtotime("$month 1 $year"));
    $pattern = "$year-".str_pad($monthNum,2,'0',STR_PAD_LEFT)."%";
    $present = $db->querySingle("SELECT COUNT(*) FROM attendance WHERE student_id=$studentId AND date LIKE '$pattern' AND status='present'");
    $absent = $db->querySingle("SELECT COUNT(*) FROM attendance WHERE student_id=$studentId AND date LIKE '$pattern' AND status='absent'");
    $late = $db->querySingle("SELECT COUNT(*) FROM attendance WHERE student_id=$studentId AND date LIKE '$pattern' AND status='late'");
    return ['present'=>$present,'absent'=>$absent,'late'=>$late];
}
function escape($str){ return htmlspecialchars($str??'',ENT_QUOTES,'UTF-8'); }

// ============================================================
// ACTION HANDLERS
// ============================================================
$action = $_GET['action'] ?? $_POST['action'] ?? 'dashboard';
$message = '';
$messageType = '';

// ADD CLASS
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='add_class'){
    $name = $db->escapeString(trim($_POST['name']));
    $section = $db->escapeString(trim($_POST['section']));
    $teacher = $db->escapeString(trim($_POST['teacher']));
    $fee = floatval($_POST['monthly_fee']);
    $db->exec("INSERT OR IGNORE INTO classes (name,section,teacher,monthly_fee) VALUES ('$name','$section','$teacher',$fee)");
    $message = "Class added successfully!"; $messageType='success';
    $action='classes';
}

// DELETE CLASS
if($action==='delete_class' && isset($_GET['id'])){
    $id = intval($_GET['id']);
    $db->exec("DELETE FROM classes WHERE id=$id");
    header("Location: index.php?action=classes&msg=deleted&type=success"); exit;
}

// ADD STUDENT
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='add_student'){
    $name=$db->escapeString(trim($_POST['name']));
    $fn=$db->escapeString(trim($_POST['father_name']));
    $phone=$db->escapeString(trim($_POST['phone']));
    $addr=$db->escapeString(trim($_POST['address']));
    $dob=$db->escapeString(trim($_POST['dob']));
    $gender=$db->escapeString(trim($_POST['gender']));
    $cid=intval($_POST['class_id']);
    $adm=$db->escapeString(trim($_POST['admission_date']));
    $roll=$db->escapeString(trim($_POST['roll_no']));
    $db->exec("INSERT INTO students (name,father_name,phone,address,dob,gender,class_id,admission_date,roll_no) VALUES ('$name','$fn','$phone','$addr','$dob','$gender',$cid,'$adm','$roll')");
    // Auto-generate fee records for current year
    $sid = $db->lastInsertRowID();
    $yr = currentYear();
    $cls = getClassById($db,$cid);
    $amt = $cls['monthly_fee'];
    foreach(months() as $m){
        $db->exec("INSERT INTO fees (student_id,month,year,amount) VALUES ($sid,'$m',$yr,$amt)");
    }
    header("Location: index.php?action=class_detail&id=$cid&msg=Student+added&type=success"); exit;
}

// DELETE STUDENT
if($action==='delete_student' && isset($_GET['id'])){
    $id=intval($_GET['id']);
    $st=$db->querySingle("SELECT class_id FROM students WHERE id=$id",true);
    $db->exec("UPDATE students SET status='inactive' WHERE id=$id");
    header("Location: index.php?action=class_detail&id={$st['class_id']}&msg=Student+removed&type=success"); exit;
}

// PROMOTE STUDENT
if($action==='promote_student' && isset($_GET['id'])){
    $id=intval($_GET['id']);
    $student=$db->querySingle("SELECT * FROM students WHERE id=$id",true);
    $nextClass=getNextClass($db,$student['class_id']);
    if($nextClass){
        $db->exec("INSERT INTO promotions (student_id,from_class_id,to_class_id,promoted_date,academic_year) VALUES ($id,{$student['class_id']},{$nextClass['id']},date('now'),".currentYear().")");
        $db->exec("UPDATE students SET class_id={$nextClass['id']} WHERE id=$id");
        // Generate fee records for new class
        $yr=currentYear();
        $amt=$nextClass['monthly_fee'];
        foreach(months() as $m){
            $exists=$db->querySingle("SELECT id FROM fees WHERE student_id=$id AND month='$m' AND year=$yr");
            if(!$exists) $db->exec("INSERT INTO fees (student_id,month,year,amount) VALUES ($id,'$m',$yr,$amt)");
        }
        header("Location: index.php?action=class_detail&id={$student['class_id']}&msg=Student+promoted+to+{$nextClass['name']}+{$nextClass['section']}&type=success"); exit;
    }
    header("Location: index.php?action=class_detail&id={$student['class_id']}&msg=No+next+class+available&type=error"); exit;
}

// PROMOTE ALL IN CLASS
if($action==='promote_all' && isset($_GET['class_id'])){
    $cid=intval($_GET['class_id']);
    $nextClass=getNextClass($db,$cid);
    if($nextClass){
        $res=$db->query("SELECT id FROM students WHERE class_id=$cid AND status='active'");
        while($r=$res->fetchArray(SQLITE3_ASSOC)){
            $sid=$r['id'];
            $db->exec("INSERT INTO promotions (student_id,from_class_id,to_class_id,promoted_date,academic_year) VALUES ($sid,$cid,{$nextClass['id']},date('now'),".currentYear().")");
            $db->exec("UPDATE students SET class_id={$nextClass['id']} WHERE id=$sid");
            $yr=currentYear(); $amt=$nextClass['monthly_fee'];
            foreach(months() as $m){
                $exists=$db->querySingle("SELECT id FROM fees WHERE student_id=$sid AND month='$m' AND year=$yr");
                if(!$exists) $db->exec("INSERT INTO fees (student_id,month,year,amount) VALUES ($sid,'$m',$yr,$amt)");
            }
        }
        header("Location: index.php?action=class_detail&id=$cid&msg=All+students+promoted&type=success"); exit;
    }
    header("Location: index.php?action=class_detail&id=$cid&msg=No+next+class&type=error"); exit;
}

// PAY FEE
if($action==='pay_fee' && isset($_GET['sid']) && isset($_GET['month']) && isset($_GET['year'])){
    $sid=intval($_GET['sid']);
    $month=$db->escapeString($_GET['month']);
    $year=intval($_GET['year']);
    $feeId=intval($_GET['fee_id']??0);
    $db->exec("UPDATE fees SET status='paid',paid_date=date('now') WHERE student_id=$sid AND month='$month' AND year=$year");
    $cid=$db->querySingle("SELECT class_id FROM students WHERE id=$sid");
    header("Location: index.php?action=student_fees&id=$sid&msg=Fee+marked+as+paid&type=success"); exit;
}

// MARK ATTENDANCE
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='mark_attendance'){
    $cid=intval($_POST['class_id']);
    $date=$db->escapeString($_POST['att_date']);
    $students_att=$_POST['attendance']??[];
    $res=$db->query("SELECT id FROM students WHERE class_id=$cid AND status='active'");
    while($r=$res->fetchArray(SQLITE3_ASSOC)){
        $sid=$r['id'];
        $st=isset($students_att[$sid])?$db->escapeString($students_att[$sid]):'absent';
        $db->exec("INSERT OR REPLACE INTO attendance (student_id,class_id,date,status) VALUES ($sid,$cid,'$date','$st')");
    }
    header("Location: index.php?action=class_detail&id=$cid&msg=Attendance+saved&type=success"); exit;
}

// EDIT STUDENT
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='edit_student'){
    $id=intval($_POST['student_id']);
    $name=$db->escapeString(trim($_POST['name']));
    $fn=$db->escapeString(trim($_POST['father_name']));
    $phone=$db->escapeString(trim($_POST['phone']));
    $addr=$db->escapeString(trim($_POST['address']));
    $dob=$db->escapeString(trim($_POST['dob']));
    $gender=$db->escapeString(trim($_POST['gender']));
    $roll=$db->escapeString(trim($_POST['roll_no']));
    $cid=intval($_POST['class_id']);
    $db->exec("UPDATE students SET name='$name',father_name='$fn',phone='$phone',address='$addr',dob='$dob',gender='$gender',roll_no='$roll',class_id=$cid WHERE id=$id");
    header("Location: index.php?action=student_detail&id=$id&msg=Updated+successfully&type=success"); exit;
}

// EDIT CLASS
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='edit_class'){
    $id=intval($_POST['class_id']);
    $name=$db->escapeString(trim($_POST['name']));
    $section=$db->escapeString(trim($_POST['section']));
    $teacher=$db->escapeString(trim($_POST['teacher']));
    $fee=floatval($_POST['monthly_fee']);
    $db->exec("UPDATE classes SET name='$name',section='$section',teacher='$teacher',monthly_fee=$fee WHERE id=$id");
    header("Location: index.php?action=classes&msg=Class+updated&type=success"); exit;
}

// GET MSG FROM REDIRECT
if(isset($_GET['msg'])){ $message=htmlspecialchars($_GET['msg']); $messageType=$_GET['type']??'success'; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>School Management System</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
--bg:#0d0f14;
--surface:#161921;
--surface2:#1e2230;
--surface3:#252a3a;
--border:#2a3045;
--accent:#4f8ef7;
--accent2:#7c3aed;
--accent3:#10b981;
--danger:#ef4444;
--warning:#f59e0b;
--text:#e8eaf0;
--text2:#9ca3af;
--text3:#6b7280;
--radius:12px;
--shadow:0 4px 24px rgba(0,0,0,.4);
}
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column}

/* SIDEBAR */
.sidebar{width:260px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;transition:.3s}
.logo{padding:24px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.logo-icon{width:40px;height:40px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px}
.logo-text{font-family:'Syne',sans-serif;font-weight:800;font-size:16px;line-height:1.1}
.logo-text span{color:var(--accent);display:block;font-size:10px;font-weight:400;letter-spacing:2px;text-transform:uppercase;color:var(--text2)}
.nav{flex:1;padding:16px 0;overflow-y:auto}
.nav-section{padding:8px 20px 4px;font-size:10px;text-transform:uppercase;letter-spacing:2px;color:var(--text3);font-weight:600}
.nav a{display:flex;align-items:center;gap:12px;padding:11px 20px;color:var(--text2);text-decoration:none;font-size:14px;font-weight:500;transition:.2s;border-left:3px solid transparent;margin:1px 0}
.nav a:hover,.nav a.active{color:var(--text);background:var(--surface2);border-left-color:var(--accent)}
.nav a i{width:18px;text-align:center;font-size:15px}
.sidebar-footer{padding:16px 20px;border-top:1px solid var(--border);font-size:12px;color:var(--text3)}

/* MAIN */
.main{margin-left:260px;flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar-left h1{font-family:'Syne',sans-serif;font-size:18px;font-weight:700}
.topbar-left span{font-size:12px;color:var(--text3)}
.topbar-right{display:flex;align-items:center;gap:12px}
.badge{background:var(--accent);color:#fff;font-size:10px;padding:3px 8px;border-radius:20px;font-weight:600}
.content{padding:28px;flex:1}

/* CARDS */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:20px}
.card-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.card-title i{color:var(--accent)}

/* STAT CARDS */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;display:flex;align-items:center;gap:16px;transition:.2s}
.stat-card:hover{border-color:var(--accent);transform:translateY(-2px)}
.stat-icon{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.stat-icon.blue{background:rgba(79,142,247,.15);color:var(--accent)}
.stat-icon.purple{background:rgba(124,58,237,.15);color:var(--accent2)}
.stat-icon.green{background:rgba(16,185,129,.15);color:var(--accent3)}
.stat-icon.orange{background:rgba(245,158,11,.15);color:var(--warning)}
.stat-icon.red{background:rgba(64, 120, 211, 0.15);color:var(--danger)}
.stat-value{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;line-height:1}
.stat-label{font-size:12px;color:var(--text2);margin-top:2px}

/* TABLE */
.table-wrap{overflow-x:auto;border-radius:var(--radius)}
table{width:100%;border-collapse:collapse;font-size:14px}
thead{background:var(--surface2)}
th{padding:12px 16px;text-align:left;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:var(--text2)}
td{padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:var(--surface2)}
.empty-row td{text-align:center;color:var(--text3);padding:40px}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;text-decoration:none;transition:.2s;font-family:'DM Sans',sans-serif;white-space:nowrap}
.btn:hover{filter:brightness(1.1);transform:translateY(-1px)}
.btn:active{transform:translateY(0)}
.btn-primary{background:var(--accent);color:#fff}
.btn-success{background:var(--accent3);color:#fff}
.btn-danger{background:var(--danger);color:#fff}
.btn-warning{background:var(--warning);color:#000}
.btn-purple{background:var(--accent2);color:#fff}
.btn-outline{background:transparent;color:var(--text2);border:1px solid var(--border)}
.btn-outline:hover{border-color:var(--accent);color:var(--accent)}
.btn-sm{padding:6px 12px;font-size:12px}
.btn-xs{padding:4px 9px;font-size:11px}
.btn-icon{width:32px;height:32px;padding:0;justify-content:center;border-radius:7px}

/* FORMS */
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group label{font-size:12px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select,.form-group textarea{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 12px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:14px;outline:none;transition:.2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,142,247,.1)}
.form-group select option{background:var(--surface2)}

/* ALERTS */
.alert{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:14px;font-weight:500;display:flex;align-items:center;gap:10px;animation:slideIn .3s ease}
.alert-success{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#34d399}
.alert-error{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#f87171}
@keyframes slideIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}

/* BADGES/STATUS */
.status{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;text-transform:uppercase}
.status-paid{background:rgba(16,185,129,.2);color:#34d399}
.status-unpaid{background:rgba(239,68,68,.2);color:#f87171}
.status-present{background:rgba(16,185,129,.2);color:#34d399}
.status-absent{background:rgba(239,68,68,.2);color:#f87171}
.status-late{background:rgba(245,158,11,.2);color:#fbbf24}
.status-active{background:rgba(79,142,247,.2);color:#60a5fa}
.status-inactive{background:rgba(107,114,128,.2);color:#9ca3af}

/* CLASS CARDS GRID */
.class-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px}
.class-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;cursor:pointer;transition:.2s;text-decoration:none;color:var(--text);display:flex;flex-direction:column;gap:14px;position:relative;overflow:hidden}
.class-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--accent),var(--accent2))}
.class-card:hover{border-color:var(--accent);transform:translateY(-3px);box-shadow:var(--shadow)}
.class-card-header{display:flex;justify-content:space-between;align-items:flex-start}
.class-name{font-family:'Syne',sans-serif;font-size:18px;font-weight:800}
.class-section-badge{background:var(--accent);color:#fff;font-size:11px;font-weight:700;padding:3px 8px;border-radius:6px}
.class-info{display:flex;flex-direction:column;gap:6px}
.class-info-row{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2)}
.class-info-row i{width:14px;color:var(--accent);font-size:12px}
.class-card-footer{display:flex;gap:8px;margin-top:4px}

/* FEES CALENDAR */
.fees-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}
.fee-month-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center;transition:.2s}
.fee-month-card.paid{border-color:var(--accent3);background:rgba(16,185,129,.08)}
.fee-month-card.current-month{border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,142,247,.1)}
.fee-month-name{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;margin-bottom:6px}
.fee-amount{font-size:20px;font-weight:700;color:var(--accent);font-family:'Syne',sans-serif}
.fee-date{font-size:11px;color:var(--text3);margin-top:4px}

/* ATTENDANCE GRID */
.att-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
.att-row{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px;display:flex;align-items:center;gap:12px}
.att-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:700;font-size:14px;flex-shrink:0}
.att-name{font-size:13px;font-weight:600;flex:1;line-height:1.3}
.att-roll{font-size:11px;color:var(--text3)}
.att-btns{display:flex;gap:4px}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(22, 57, 68, 0.7);z-index:200;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.modal-overlay.active{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;width:90%;max-width:600px;max-height:90vh;overflow-y:auto;animation:modalIn .25s ease}
@keyframes modalIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}
.modal-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:700;margin-bottom:20px;display:flex;align-items:center;gap:10px}
.modal-title i{color:var(--accent)}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)}

/* PRINT */
@media print{
.sidebar,.topbar,.no-print{display:none!important}
.main{margin:0}
.content{padding:0}
body{background:#fff;color:#000}
.card{border:1px solid #ddd;box-shadow:none}
table{font-size:12px}
th,td{border:1px solid #ddd}
.btn{display:none}
}

/* RESPONSIVE */
@media(max-width:768px){
.sidebar{width:60px}
.sidebar .logo-text,.sidebar .nav a span,.sidebar .nav-section,.sidebar-footer{display:none}
.main{margin-left:60px}
.stats{grid-template-columns:1fr 1fr}
.class-grid{grid-template-columns:1fr}
}

/* SCROLLBAR */
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:var(--surface)}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--text3)}

.divider{height:1px;background:var(--border);margin:20px 0}
.breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text3);margin-bottom:20px}
.breadcrumb a{color:var(--accent);text-decoration:none}
.breadcrumb span{color:var(--text3)}
.actions-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:20px}
.student-avatar-lg{width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:22px;font-family:'Syne',sans-serif;font-weight:800;flex-shrink:0}
.profile-header{display:flex;align-items:center;gap:20px;margin-bottom:24px}
.profile-info h2{font-family:'Syne',sans-serif;font-size:22px;font-weight:800}
.profile-info p{color:var(--text2);font-size:14px}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px}
.info-item label{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--text3);font-weight:600}
.info-item p{font-size:14px;color:var(--text);margin-top:3px}
.tab-bar{display:flex;border-bottom:1px solid var(--border);margin-bottom:20px;gap:4px}
.tab{padding:10px 18px;font-size:14px;font-weight:600;color:var(--text3);cursor:pointer;border-bottom:2px solid transparent;transition:.2s;text-decoration:none}
.tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.tab:hover{color:var(--text)}
.progress-bar-wrap{background:var(--surface2);border-radius:4px;height:6px;margin-top:4px}
.progress-bar{height:6px;border-radius:4px;background:var(--accent3)}
</style>
</head>
<body>

<!-- SIDEBAR -->
<nav class="sidebar">
  <div class="logo">
    <div class="logo-icon"><i class="fa fa-graduation-cap" style="color:#fff"></i></div>
    <div class="logo-text">School<span>Management System</span></div>
  </div>
  <div class="nav">
    <div class="nav-section">Main</div>
    <a href="?action=dashboard" class="<?=($action==='dashboard')?'active':''?>"><i class="fa fa-chart-pie"></i><span>Dashboard</span></a>
    <a href="?action=classes" class="<?=($action==='classes'||$action==='class_detail')?'active':''?>"><i class="fa fa-school"></i><span>Classes</span></a>
    <a href="?action=students" class="<?=($action==='students'||$action==='student_detail')?'active':''?>"><i class="fa fa-users"></i><span>All Students</span></a>
    <div class="nav-section">Finance</div>
    <a href="?action=fees" class="<?=($action==='fees'||$action==='student_fees')?'active':''?>"><i class="fa fa-dollar-sign"></i><span>Fee Management</span></a>
    <a href="?action=fee_report" class="<?=($action==='fee_report')?'active':''?>"><i class="fa fa-file-invoice-dollar"></i><span>Fee Reports</span></a>
    <div class="nav-section">Academics</div>
    <a href="?action=attendance" class="<?=($action==='attendance')?'active':''?>"><i class="fa fa-calendar-check"></i><span>Attendance</span></a>
    <a href="?action=attendance_report" class="<?=($action==='attendance_report')?'active':''?>"><i class="fa fa-chart-bar"></i><span>Att. Report</span></a>
    <a href="?action=promotions" class="<?=($action==='promotions')?'active':''?>"><i class="fa fa-level-up-alt"></i><span>Promotions</span></a>
    <div class="nav-section">Settings</div>
    <a href="?action=settings" class="<?=($action==='settings')?'active':''?>"><i class="fa fa-cog"></i><span>Settings</span></a>
  </div>
  <div class="sidebar-footer">
    <div style="font-size:11px">Build By Shakeel Ahmad</div>
  </div>
</nav>

<!-- MAIN -->
<div class="main">
<div class="topbar">
  <div class="topbar-left">
    <h1><?php
    $titles=['dashboard'=>'Dashboard','classes'=>'Classes','class_detail'=>'Class Detail',
    'students'=>'Students','student_detail'=>'Student Profile','fees'=>'Fee Management',
    'student_fees'=>'Student Fees','fee_report'=>'Fee Reports','attendance'=>'Attendance',
    'attendance_report'=>'Attendance Report','promotions'=>'Promotions','settings'=>'Settings'];
    echo $titles[$action]??'School Management';
    ?></h1>
    <span><?=date('l, d F Y')?></span>
  </div>
  <div class="topbar-right">
    <span style="font-size:13px;color:var(--text2)"><i class="fa fa-clock" style="color:var(--accent)"></i> <span id="clock"></span></span>
    <span class="badge">Admin</span>
  </div>
</div>

<div class="content">
<?php if($message): ?>
<div class="alert alert-<?=$messageType?>"><i class="fa fa-<?=$messageType==='success'?'check-circle':'exclamation-circle'?>"></i><?=escape($message)?></div>
<?php endif; ?>

<?php
// ============================================================
// VIEWS
// ============================================================

// ---- DASHBOARD ----
if($action==='dashboard'){
    $totalStudents=$db->querySingle("SELECT COUNT(*) FROM students WHERE status='active'");
    $totalClasses=$db->querySingle("SELECT COUNT(*) FROM classes");
    $curMonth=currentMonth(); $curYear=currentYear();
    $feesPaid=$db->querySingle("SELECT SUM(amount) FROM fees WHERE status='paid' AND year=$curYear");
    $feesUnpaid=$db->querySingle("SELECT SUM(amount) FROM fees WHERE status='unpaid' AND year=$curYear");
    $todayAtt=$db->querySingle("SELECT COUNT(*) FROM attendance WHERE date=date('now') AND status='present'");
    $todayAbsent=$db->querySingle("SELECT COUNT(*) FROM attendance WHERE date=date('now') AND status='absent'");
    $totalPromotions=$db->querySingle("SELECT COUNT(*) FROM promotions WHERE academic_year=$curYear");
?>
<div class="stats">
  <div class="stat-card"><div class="stat-icon blue"><i class="fa fa-users"></i></div><div><div class="stat-value"><?=$totalStudents?></div><div class="stat-label">Total Students</div></div></div>
  <div class="stat-card"><div class="stat-icon purple"><i class="fa fa-school"></i></div><div><div class="stat-value"><?=$totalClasses?></div><div class="stat-label">Total Classes</div></div></div>
  <div class="stat-card"><div class="stat-icon green"><i class="fa fa-dollar-sign"></i></div><div><div class="stat-value">₨ <?=number_format($feesPaid??0)?></div><div class="stat-label">Fees Collected (<?=$curYear?>)</div></div></div>
  <div class="stat-card"><div class="stat-icon orange"><i class="fa fa-exclamation-circle"></i></div><div><div class="stat-value">₨ <?=number_format($feesUnpaid??0)?></div><div class="stat-label">Fees Pending (<?=$curYear?>)</div></div></div>
  <div class="stat-card"><div class="stat-icon green"><i class="fa fa-calendar-check"></i></div><div><div class="stat-value"><?=$todayAtt?></div><div class="stat-label">Present Today</div></div></div>
  <div class="stat-card"><div class="stat-icon red"><i class="fa fa-calendar-times"></i></div><div><div class="stat-value"><?=$todayAbsent?></div><div class="stat-label">Absent Today</div></div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
<div class="card">
  <div class="card-title"><i class="fa fa-school"></i> Classes Overview</div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Class</th><th>Section</th><th>Teacher</th><th>Students</th><th>Monthly Fee</th></tr></thead>
    <tbody>
    <?php
    $res=getClasses($db);
    $hasRows=false;
    while($c=$res->fetchArray(SQLITE3_ASSOC)){
        $hasRows=true;
        $count=$db->querySingle("SELECT COUNT(*) FROM students WHERE class_id={$c['id']} AND status='active'");
    ?>
    <tr>
      <td><a href="?action=class_detail&id=<?=$c['id']?>" style="color:var(--accent);text-decoration:none;font-weight:600"><?=escape($c['name'])?></a></td>
      <td><span class="status" style="background:rgba(79,142,247,.15);color:var(--accent)"><?=escape($c['section'])?></span></td>
      <td><?=escape($c['teacher'])?></td>
      <td><strong><?=$count?></strong></td>
      <td>₨ <?=number_format($c['monthly_fee'])?></td>
    </tr>
    <?php } if(!$hasRows): ?><tr class="empty-row"><td colspan="5"><i class="fa fa-school" style="font-size:24px;margin-bottom:8px;display:block;color:var(--text3)"></i>No classes yet</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card">
  <div class="card-title"><i class="fa fa-clock-rotate-left"></i> Recent Promotions</div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Student</th><th>From</th><th>To</th><th>Date</th></tr></thead>
    <tbody>
    <?php
    $res=$db->query("SELECT p.*,s.name as sname,fc.name as fname,fc.section as fsec,tc.name as tname,tc.section as tsec FROM promotions p JOIN students s ON s.id=p.student_id LEFT JOIN classes fc ON fc.id=p.from_class_id LEFT JOIN classes tc ON tc.id=p.to_class_id ORDER BY p.id DESC LIMIT 10");
    $hasRows=false;
    while($r=$res->fetchArray(SQLITE3_ASSOC)){
        $hasRows=true;
        echo "<tr><td>".escape($r['sname'])."</td><td>".escape($r['fname'].' '.$r['fsec'])."</td><td>".escape($r['tname'].' '.$r['tsec'])."</td><td style='font-size:12px;color:var(--text3)'>".escape($r['promoted_date'])."</td></tr>";
    }
    if(!$hasRows) echo "<tr class='empty-row'><td colspan='4'><i class='fa fa-level-up-alt' style='font-size:24px;margin-bottom:8px;display:block;color:var(--text3)'></i>No promotions yet</td></tr>";
    ?>
    </tbody>
  </table>
  </div>
</div>
</div>

<div class="card" style="margin-top:20px">
  <div class="card-title"><i class="fa fa-money-bill-wave"></i> <?=currentMonth()?> <?=currentYear()?> — Fee Summary by Class</div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Class</th><th>Section</th><th>Total Students</th><th>Paid</th><th>Unpaid</th><th>Collection</th><th>Progress</th></tr></thead>
    <tbody>
    <?php
    $res=getClasses($db);
    while($c=$res->fetchArray(SQLITE3_ASSOC)){
        $students=$db->query("SELECT id FROM students WHERE class_id={$c['id']} AND status='active'");
        $sids=[];
        while($s=$students->fetchArray(SQLITE3_ASSOC)) $sids[]=$s['id'];
        if(!$sids){echo "<tr><td colspan='7' style='color:var(--text3);font-size:12px;text-align:center'>".escape($c['name'])." ".escape($c['section'])." — No students</td></tr>"; continue;}
        $inList=implode(',',$sids);
        $paid=$db->querySingle("SELECT COUNT(*) FROM fees WHERE student_id IN ($inList) AND month='".currentMonth()."' AND year=".currentYear()." AND status='paid'");
        $unpaid=$db->querySingle("SELECT COUNT(*) FROM fees WHERE student_id IN ($inList) AND month='".currentMonth()."' AND year=".currentYear()." AND status='unpaid'");
        $col=$db->querySingle("SELECT SUM(amount) FROM fees WHERE student_id IN ($inList) AND month='".currentMonth()."' AND year=".currentYear()." AND status='paid'");
        $total=count($sids);
        $pct=$total>0?round($paid/$total*100):0;
        echo "<tr>
        <td><a href='?action=class_detail&id={$c['id']}' style='color:var(--accent);text-decoration:none;font-weight:600'>".escape($c['name'])."</a></td>
        <td>".escape($c['section'])."</td>
        <td>$total</td>
        <td><span class='status status-paid'>$paid paid</span></td>
        <td><span class='status status-unpaid'>$unpaid pending</span></td>
        <td><strong>₨ ".number_format($col??0)."</strong></td>
        <td style='min-width:100px'><div style='display:flex;align-items:center;gap:6px'><div class='progress-bar-wrap' style='flex:1'><div class='progress-bar' style='width:{$pct}%'></div></div><span style='font-size:12px;color:var(--text3)'>{$pct}%</span></div></td>
        </tr>";
    }
    ?>
    </tbody>
  </table>
  </div>
</div>
<?php
}

// ---- CLASSES LIST ----
elseif($action==='classes'){
?>
<div class="actions-bar">
  <button onclick="document.getElementById('addClassModal').classList.add('active')" class="btn btn-primary"><i class="fa fa-plus"></i> Add New Class</button>
</div>

<div class="class-grid">
<?php
$res=getClasses($db);
$hasRows=false;
while($c=$res->fetchArray(SQLITE3_ASSOC)){
    $hasRows=true;
    $count=$db->querySingle("SELECT COUNT(*) FROM students WHERE class_id={$c['id']} AND status='active'");
    $paidMonth=$db->querySingle("SELECT COUNT(*) FROM fees f JOIN students s ON s.id=f.student_id WHERE s.class_id={$c['id']} AND f.month='".currentMonth()."' AND f.year=".currentYear()." AND f.status='paid' AND s.status='active'");
?>
<div class="class-card" onclick="window.location='?action=class_detail&id=<?=$c['id']?>'">
  <div class="class-card-header">
    <div class="class-name"><?=escape($c['name'])?></div>
    <div class="class-section-badge">Section <?=escape($c['section'])?></div>
  </div>
  <div class="class-info">
    <div class="class-info-row"><i class="fa fa-users"></i> <?=$count?> Students</div>
    <div class="class-info-row"><i class="fa fa-chalkboard-teacher"></i> <?=escape($c['teacher']??'N/A')?></div>
    <div class="class-info-row"><i class="fa fa-dollar-sign"></i> ₨ <?=number_format($c['monthly_fee'])?>/month</div>
    <div class="class-info-row"><i class="fa fa-check-circle" style="color:var(--accent3)"></i> <?=$paidMonth?>/<?=$count?> fees paid this month</div>
  </div>
  <div class="class-card-footer" onclick="event.stopPropagation()">
    <a href="?action=class_detail&id=<?=$c['id']?>" class="btn btn-outline btn-sm"><i class="fa fa-eye"></i> View</a>
    <button onclick="openEditClass(<?=$c['id']?>,'<?=escape($c['name'])?>','<?=escape($c['section'])?>','<?=escape($c['teacher'])?>',<?=$c['monthly_fee']?>)" class="btn btn-outline btn-sm"><i class="fa fa-pen"></i> Edit</button>
    <a href="?action=delete_class&id=<?=$c['id']?>" onclick="return confirm('Delete this class?')" class="btn btn-outline btn-sm" style="color:var(--danger)"><i class="fa fa-trash"></i></a>
  </div>
</div>
<?php } if(!$hasRows): ?>
<div class="card" style="grid-column:1/-1;text-align:center;padding:40px;color:var(--text3)">
  <i class="fa fa-school" style="font-size:40px;margin-bottom:12px;display:block"></i>
  <p>No classes added yet. Click "Add New Class" to get started.</p>
</div>
<?php endif; ?>
</div>

<!-- Add Class Modal -->
<div class="modal-overlay" id="addClassModal">
<div class="modal">
  <div class="modal-title"><i class="fa fa-plus"></i> Add New Class</div>
  <form method="POST" action="?action=add_class">
    <div class="form-grid">
      <div class="form-group"><label>Class Name</label><input type="text" name="name" placeholder="e.g. Class 1" required></div>
      <div class="form-group"><label>Section</label><input type="text" name="section" placeholder="e.g. A" required></div>
      <div class="form-group"><label>Class Teacher</label><input type="text" name="teacher" placeholder="Teacher name"></div>
      <div class="form-group"><label>Monthly Fee (₨)</label><input type="number" name="monthly_fee" placeholder="2000" value="0"></div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="document.getElementById('addClassModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Class</button>
    </div>
  </form>
</div>
</div>

<!-- Edit Class Modal -->
<div class="modal-overlay" id="editClassModal">
<div class="modal">
  <div class="modal-title"><i class="fa fa-pen"></i> Edit Class</div>
  <form method="POST" action="?action=edit_class">
    <input type="hidden" name="class_id" id="edit_class_id">
    <div class="form-grid">
      <div class="form-group"><label>Class Name</label><input type="text" name="name" id="edit_class_name" required></div>
      <div class="form-group"><label>Section</label><input type="text" name="section" id="edit_class_section" required></div>
      <div class="form-group"><label>Class Teacher</label><input type="text" name="teacher" id="edit_class_teacher"></div>
      <div class="form-group"><label>Monthly Fee (₨)</label><input type="number" name="monthly_fee" id="edit_class_fee"></div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="document.getElementById('editClassModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Update Class</button>
    </div>
  </form>
</div>
</div>
<script>
function openEditClass(id,name,section,teacher,fee){
  document.getElementById('edit_class_id').value=id;
  document.getElementById('edit_class_name').value=name;
  document.getElementById('edit_class_section').value=section;
  document.getElementById('edit_class_teacher').value=teacher;
  document.getElementById('edit_class_fee').value=fee;
  document.getElementById('editClassModal').classList.add('active');
}
</script>
<?php
}

// ---- CLASS DETAIL ----
elseif($action==='class_detail' && isset($_GET['id'])){
    $classId=intval($_GET['id']);
    $class=getClassById($db,$classId);
    if(!$class){ echo "<div class='alert alert-error'>Class not found</div>"; }
    else {
    $nextClass=getNextClass($db,$classId);
    $tab=$_GET['tab']??'students';
    $students=getStudentsByClass($db,$classId);
    $studentArr=[];
    while($s=$students->fetchArray(SQLITE3_ASSOC)) $studentArr[]=$s;
    $todayDate=date('Y-m-d');
?>
<div class="breadcrumb"><a href="?action=classes"><i class="fa fa-school"></i> Classes</a><span>/</span><span><?=escape($class['name'])?> <?=escape($class['section'])?></span></div>

<div class="card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;padding:20px 24px">
  <div style="display:flex;align-items:center;gap:16px">
    <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:22px"><i class="fa fa-school" style="color:#fff"></i></div>
    <div>
      <div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:800"><?=escape($class['name'])?> — Section <?=escape($class['section'])?></div>
      <div style="color:var(--text2);font-size:13px"><i class="fa fa-chalkboard-teacher" style="color:var(--accent)"></i> <?=escape($class['teacher']??'No teacher assigned')?> &nbsp;|&nbsp; <i class="fa fa-users" style="color:var(--accent)"></i> <?=count($studentArr)?> Students &nbsp;|&nbsp; <i class="fa fa-dollar-sign" style="color:var(--accent3)"></i> ₨ <?=number_format($class['monthly_fee'])?>/month</div>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button onclick="document.getElementById('addStudentModal').classList.add('active')" class="btn btn-primary btn-sm"><i class="fa fa-user-plus"></i> Add Student</button>
    <?php if($nextClass): ?>
    <a href="?action=promote_all&class_id=<?=$classId?>" onclick="return confirm('Promote ALL students in this class to <?=escape($nextClass['name'].' '.$nextClass['section'])?>?')" class="btn btn-purple btn-sm"><i class="fa fa-level-up-alt"></i> Promote All</a>
    <?php endif; ?>
    <button onclick="window.print()" class="btn btn-outline btn-sm"><i class="fa fa-print"></i> Print</button>
  </div>
</div>

<div class="tab-bar">
  <a href="?action=class_detail&id=<?=$classId?>&tab=students" class="tab <?=$tab==='students'?'active':''?>"><i class="fa fa-users"></i> Students</a>
  <a href="?action=class_detail&id=<?=$classId?>&tab=fees" class="tab <?=$tab==='fees'?'active':''?>"><i class="fa fa-dollar-sign"></i> Fees</a>
  <a href="?action=class_detail&id=<?=$classId?>&tab=attendance" class="tab <?=$tab==='attendance'?'active':''?>"><i class="fa fa-calendar-check"></i> Attendance</a>
</div>

<?php if($tab==='students'): ?>
<div class="card">
  <div class="card-title"><i class="fa fa-users"></i> Students — <?=escape($class['name'])?> <?=escape($class['section'])?></div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Roll No</th><th>Name</th><th>Father Name</th><th>Phone</th><th>Gender</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(!$studentArr): ?>
    <tr class="empty-row"><td colspan="7"><i class="fa fa-users" style="font-size:28px;margin-bottom:8px;display:block;color:var(--text3)"></i>No students enrolled. Click "Add Student" above.</td></tr>
    <?php else: foreach($studentArr as $i=>$s): ?>
    <tr>
      <td style="color:var(--text3)"><?=$i+1?></td>
      <td><strong><?=escape($s['roll_no'])?></strong></td>
      <td>
        <a href="?action=student_detail&id=<?=$s['id']?>" style="color:var(--accent);text-decoration:none;font-weight:600;display:flex;align-items:center;gap:8px">
          <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0"><?=strtoupper(substr($s['name'],0,2))?></div>
          <?=escape($s['name'])?>
        </a>
      </td>
      <td><?=escape($s['father_name'])?></td>
      <td><?=escape($s['phone'])?></td>
      <td><span class="status" style="background:rgba(<?=$s['gender']==='Male'?'79,142,247':'239,68,68'?>, .15);color:var(--<?=$s['gender']==='Male'?'accent':'danger'?>)"><?=escape($s['gender'])?></span></td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <a href="?action=student_fees&id=<?=$s['id']?>" class="btn btn-xs btn-success"><i class="fa fa-dollar-sign"></i> Fees</a>
          <a href="?action=student_detail&id=<?=$s['id']?>" class="btn btn-xs btn-outline"><i class="fa fa-eye"></i></a>
          <?php if($nextClass): ?>
          <a href="?action=promote_student&id=<?=$s['id']?>" onclick="return confirm('Promote <?=escape($s['name'])?> to <?=escape($nextClass['name'].' '.$nextClass['section'])?>?')" class="btn btn-xs btn-purple"><i class="fa fa-level-up-alt"></i> Promote</a>
          <?php endif; ?>
          <a href="?action=delete_student&id=<?=$s['id']?>" onclick="return confirm('Remove this student?')" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i></a>
        </div>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php elseif($tab==='fees'): 
    $selMonth=$_GET['month']??currentMonth();
    $selYear=$_GET['year']??currentYear();
?>
<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
    <div class="card-title" style="margin:0"><i class="fa fa-dollar-sign"></i> Fee Status — <?=$selMonth?> <?=$selYear?></div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <form method="GET" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="action" value="class_detail">
        <input type="hidden" name="id" value="<?=$classId?>">
        <input type="hidden" name="tab" value="fees">
        <select name="month" class="form-group select" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 10px;color:var(--text);font-size:13px">
          <?php foreach(months() as $m): ?>
          <option value="<?=$m?>" <?=$m==$selMonth?'selected':''?>><?=$m?></option>
          <?php endforeach; ?>
        </select>
        <select name="year" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 10px;color:var(--text);font-size:13px">
          <?php for($y=date('Y')-1;$y<=date('Y')+1;$y++): ?>
          <option value="<?=$y?>" <?=$y==$selYear?'selected':''?>><?=$y?></option>
          <?php endfor; ?>
        </select>
        <button type="submit" class="btn btn-outline btn-sm"><i class="fa fa-search"></i></button>
      </form>
      <button onclick="window.print()" class="btn btn-outline btn-sm no-print"><i class="fa fa-print"></i> Print</button>
    </div>
  </div>
  
  <div id="printableArea">
  <div style="display:none" class="print-only" style="text-align:center;margin-bottom:20px">
    <h2><?=escape($class['name'])?> <?=escape($class['section'])?> — Fee Report <?=$selMonth?> <?=$selYear?></h2>
    <p>Generated: <?=date('d M Y')?></p>
  </div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Roll No</th><th>Student Name</th><th>Father Name</th><th>Fee Amount</th><th>Status</th><th>Paid Date</th><th class="no-print">Action</th></tr></thead>
    <tbody>
    <?php if(!$studentArr): ?>
    <tr class="empty-row"><td colspan="8">No students in this class.</td></tr>
    <?php else:
    $paidCount=0; $unpaidCount=0;
    foreach($studentArr as $i=>$s):
        $fee=getFeeStatus($db,$s['id'],$selMonth,$selYear);
        $isPaid=$fee && $fee['status']==='paid';
        if($isPaid) $paidCount++; else $unpaidCount++;
    ?>
    <tr>
      <td style="color:var(--text3)"><?=$i+1?></td>
      <td><?=escape($s['roll_no'])?></td>
      <td><strong><?=escape($s['name'])?></strong></td>
      <td><?=escape($s['father_name'])?></td>
      <td><strong>₨ <?=number_format($fee['amount']??$class['monthly_fee'])?></strong></td>
      <td><span class="status <?=$isPaid?'status-paid':'status-unpaid'?>"><?=$isPaid?'Paid':'Unpaid'?></span></td>
      <td style="font-size:12px;color:var(--text3)"><?=$fee&&$fee['paid_date']?date('d M Y',strtotime($fee['paid_date']??'')):'—'?></td>
      <td class="no-print">
        <?php if(!$isPaid): ?>
        <a href="?action=pay_fee&sid=<?=$s['id']?>&month=<?=$selMonth?>&year=<?=$selYear?>" onclick="return confirm('Mark fee as paid?')" class="btn btn-xs btn-success"><i class="fa fa-check"></i> Pay Fee</a>
        <?php else: ?>
        <span style="color:var(--accent3);font-size:12px"><i class="fa fa-check-circle"></i> Paid</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:var(--surface2);font-weight:600">
      <td colspan="5" style="text-align:right;padding-right:16px">Summary:</td>
      <td colspan="3"><span class="status status-paid"><?=$paidCount?> Paid</span> &nbsp; <span class="status status-unpaid"><?=$unpaidCount?> Unpaid</span></td>
    </tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  </div>
</div>

<?php elseif($tab==='attendance'): 
    $attDate=$_GET['att_date']??date('Y-m-d');
?>
<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
    <div class="card-title" style="margin:0"><i class="fa fa-calendar-check"></i> Attendance — <?=date('d F Y',strtotime($attDate))?></div>
    <form method="GET" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="action" value="class_detail">
      <input type="hidden" name="id" value="<?=$classId?>">
      <input type="hidden" name="tab" value="attendance">
      <input type="date" name="att_date" value="<?=$attDate?>" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 10px;color:var(--text);font-size:13px">
      <button type="submit" class="btn btn-outline btn-sm"><i class="fa fa-search"></i></button>
    </form>
  </div>
  
  <?php if(!$studentArr): ?>
  <div style="text-align:center;padding:40px;color:var(--text3)"><i class="fa fa-users" style="font-size:28px;margin-bottom:8px;display:block"></i>No students to mark attendance.</div>
  <?php else: ?>
  <form method="POST" action="?action=mark_attendance">
    <input type="hidden" name="class_id" value="<?=$classId?>">
    <input type="hidden" name="att_date" value="<?=$attDate?>">
    
    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
      <button type="button" onclick="markAll('present')" class="btn btn-success btn-sm no-print"><i class="fa fa-check"></i> Mark All Present</button>
      <button type="button" onclick="markAll('absent')" class="btn btn-danger btn-sm no-print"><i class="fa fa-times"></i> Mark All Absent</button>
    </div>
    
    <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Roll No</th><th>Student Name</th><th>Father</th><th>Attendance</th><th class="no-print">Quick Set</th></tr></thead>
      <tbody>
      <?php foreach($studentArr as $i=>$s):
          $existingAtt=getAttendance($db,$s['id'],$attDate);
          $curStatus=$existingAtt?$existingAtt['status']:'present';
      ?>
      <tr>
        <td style="color:var(--text3)"><?=$i+1?></td>
        <td><?=escape($s['roll_no'])?></td>
        <td style="font-weight:600"><?=escape($s['name'])?></td>
        <td style="color:var(--text2)"><?=escape($s['father_name'])?></td>
        <td>
          <select name="attendance[<?=$s['id']?>]" class="att-select" data-sid="<?=$s['id']?>" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 10px;color:var(--text);font-size:13px">
            <option value="present" <?=$curStatus==='present'?'selected':''?>>✅ Present</option>
            <option value="absent" <?=$curStatus==='absent'?'selected':''?>>❌ Absent</option>
            <option value="late" <?=$curStatus==='late'?'selected':''?>>⏰ Late</option>
          </select>
        </td>
        <td class="no-print">
          <div style="display:flex;gap:5px">
            <button type="button" onclick="setSingle(<?=$s['id']?>,'present')" class="btn btn-xs btn-success"><i class="fa fa-check"></i></button>
            <button type="button" onclick="setSingle(<?=$s['id']?>,'absent')" class="btn btn-xs btn-danger"><i class="fa fa-times"></i></button>
            <button type="button" onclick="setSingle(<?=$s['id']?>,'late')" class="btn btn-xs btn-warning"><i class="fa fa-clock"></i></button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    
    <div style="margin-top:16px;display:flex;justify-content:flex-end">
      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Attendance</button>
    </div>
  </form>
  <script>
  function markAll(status){
    document.querySelectorAll('.att-select').forEach(s=>s.value=status);
  }
  function setSingle(sid,status){
    document.querySelector('.att-select[data-sid="'+sid+'"]').value=status;
  }
  </script>
  <?php endif; ?>
</div>
<?php endif; // end tab ?>

<!-- Add Student Modal -->
<div class="modal-overlay" id="addStudentModal">
<div class="modal" style="max-width:680px">
  <div class="modal-title"><i class="fa fa-user-plus"></i> Add New Student</div>
  <form method="POST" action="?action=add_student">
    <input type="hidden" name="class_id" value="<?=$classId?>">
    <div class="form-grid" style="grid-template-columns:repeat(2,1fr)">
      <div class="form-group"><label>Full Name *</label><input type="text" name="name" required placeholder="Student full name"></div>
      <div class="form-group"><label>Father's Name</label><input type="text" name="father_name" placeholder="Father's name"></div>
      <div class="form-group"><label>Roll Number</label><input type="text" name="roll_no" placeholder="e.g. 001"></div>
      <div class="form-group"><label>Gender</label>
        <select name="gender">
          <option value="Male">Male</option>
          <option value="Female">Female</option>
          <option value="Other">Other</option>
        </select>
      </div>
      <div class="form-group"><label>Date of Birth</label><input type="date" name="dob"></div>
      <div class="form-group"><label>Phone</label><input type="text" name="phone" placeholder="03XX-XXXXXXX"></div>
      <div class="form-group" style="grid-column:1/-1"><label>Address</label><input type="text" name="address" placeholder="Home address"></div>
      <div class="form-group"><label>Admission Date</label><input type="date" name="admission_date" value="<?=date('Y-m-d')?>"></div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="document.getElementById('addStudentModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Enroll Student</button>
    </div>
  </form>
</div>
</div>
<?php }
}

// ---- ALL STUDENTS ----
elseif($action==='students'){
    $search=$_GET['q']??'';
    $filterClass=$_GET['class_id']??'';
    $filterGender=$_GET['gender']??'';
    $where="s.status='active'";
    if($search) $where.=" AND (s.name LIKE '%".addslashes($search)."%' OR s.father_name LIKE '%".addslashes($search)."%' OR s.roll_no LIKE '%".addslashes($search)."%')";
    if($filterClass) $where.=" AND s.class_id=".intval($filterClass);
    if($filterGender) $where.=" AND s.gender='".addslashes($filterGender)."'";
    $allStudents=$db->query("SELECT s.*,c.name as class_name,c.section FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE $where ORDER BY c.name,c.section,s.roll_no,s.name");
    $studentList=[];
    if($allStudents){
        while($s=$allStudents->fetchArray(SQLITE3_ASSOC)) $studentList[]=$s;
    }
?>
<div class="card">
  <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:0">
    <input type="hidden" name="action" value="students">
    <div class="form-group" style="flex:1;min-width:180px;margin:0"><input type="text" name="q" value="<?=escape($search)?>" placeholder="🔍 Search students..."></div>
    <select name="class_id" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text);font-size:14px">
      <option value="">All Classes</option>
      <?php $cr=getClasses($db); while($c=$cr->fetchArray(SQLITE3_ASSOC)): ?>
      <option value="<?=$c['id']?>" <?=$filterClass==$c['id']?'selected':''?>><?=escape($c['name'])?> <?=escape($c['section'])?></option>
      <?php endwhile; ?>
    </select>
    <select name="gender" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text);font-size:14px">
      <option value="">All Genders</option>
      <option value="Male" <?=$filterGender==='Male'?'selected':''?>>Male</option>
      <option value="Female" <?=$filterGender==='Female'?'selected':''?>>Female</option>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Search</button>
    <a href="?action=students" class="btn btn-outline">Clear</a>
    <button onclick="window.print()" type="button" class="btn btn-outline no-print"><i class="fa fa-print"></i></button>
  </form>
</div>

<div class="card">
  <div class="card-title"><i class="fa fa-users"></i> All Students <span style="font-size:13px;color:var(--text3);font-weight:400">(<?=count($studentList)?> found)</span></div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Roll No</th><th>Name</th><th>Father</th><th>Class</th><th>Gender</th><th>Phone</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(!$studentList): ?>
    <tr class="empty-row"><td colspan="8"><i class="fa fa-users" style="font-size:28px;margin-bottom:8px;display:block;color:var(--text3)"></i>No students found</td></tr>
    <?php else: foreach($studentList as $i=>$s): ?>
    <tr>
      <td style="color:var(--text3)"><?=$i+1?></td>
      <td><?=escape($s['roll_no'])?></td>
      <td><a href="?action=student_detail&id=<?=$s['id']?>" style="color:var(--accent);font-weight:600;text-decoration:none"><?=escape($s['name'])?></a></td>
      <td><?=escape($s['father_name'])?></td>
      <td><a href="?action=class_detail&id=<?=$s['class_id']?>" style="color:var(--text2);text-decoration:none"><?=escape($s['class_name'])?> <?=escape($s['section'])?></a></td>
      <td><span class="status" style="background:rgba(<?=$s['gender']==='Male'?'79,142,247':'239,68,68'?>,.15);color:var(--<?=$s['gender']==='Male'?'accent':'danger'?>)"><?=escape($s['gender'])?></span></td>
      <td style="font-size:13px"><?=escape($s['phone'])?></td>
      <td>
        <div style="display:flex;gap:5px">
          <a href="?action=student_detail&id=<?=$s['id']?>" class="btn btn-xs btn-outline"><i class="fa fa-eye"></i></a>
          <a href="?action=student_fees&id=<?=$s['id']?>" class="btn btn-xs btn-success"><i class="fa fa-dollar-sign"></i></a>
          <a href="?action=delete_student&id=<?=$s['id']?>" onclick="return confirm('Remove student?')" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i></a>
        </div>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php
}

// ---- STUDENT DETAIL ----
elseif($action==='student_detail' && isset($_GET['id'])){
    $sid=intval($_GET['id']);
    $student=$db->querySingle("SELECT s.*,c.name as class_name,c.section,c.monthly_fee FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.id=$sid",true);
    if(!$student){ echo "<div class='alert alert-error'>Student not found</div>"; }
    else {
    $initials=strtoupper(implode('',array_slice(explode(' ',$student['name']),0,2)));
    $initials=strlen($initials)>=2?substr($initials,0,1).substr($initials,-1):substr($initials,0,2);
    $nextClass=getNextClass($db,$student['class_id']??0);
    // Attendance Summary
    $attSummary=getAttendanceSummary($db,$sid,currentMonth(),currentYear());
    $totalAttDays=$attSummary['present']+$attSummary['absent']+$attSummary['late'];
    $attPct=$totalAttDays>0?round($attSummary['present']/$totalAttDays*100):0;
    // Fee Summary
    $yearFeesPaid=$db->querySingle("SELECT SUM(amount) FROM fees WHERE student_id=$sid AND year=".currentYear()." AND status='paid'");
    $yearFeesTotal=$db->querySingle("SELECT SUM(amount) FROM fees WHERE student_id=$sid AND year=".currentYear());
?>
<div class="breadcrumb"><a href="?action=students"><i class="fa fa-users"></i> Students</a><span>/</span><span><?=escape($student['name'])?></span></div>

<div class="card">
  <div class="profile-header">
    <div class="student-avatar-lg"><?=$initials?></div>
    <div class="profile-info">
      <h2><?=escape($student['name'])?></h2>
      <p><i class="fa fa-school" style="color:var(--accent)"></i> <?=escape($student['class_name'])?> <?=escape($student['section'])?> &nbsp;|&nbsp; Roll No: <strong><?=escape($student['roll_no'])?></strong> &nbsp;|&nbsp; <span class="status status-active">Active</span></p>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap" class="no-print">
      <button onclick="document.getElementById('editStudentModal').classList.add('active')" class="btn btn-outline btn-sm"><i class="fa fa-pen"></i> Edit</button>
      <a href="?action=student_fees&id=<?=$sid?>" class="btn btn-success btn-sm"><i class="fa fa-dollar-sign"></i> View Fees</a>
      <?php if($nextClass): ?>
      <a href="?action=promote_student&id=<?=$sid?>" onclick="return confirm('Promote to <?=escape($nextClass['name'].' '.$nextClass['section'])?>?')" class="btn btn-purple btn-sm"><i class="fa fa-level-up-alt"></i> Promote</a>
      <?php endif; ?>
      <button onclick="window.print()" class="btn btn-outline btn-sm"><i class="fa fa-print"></i></button>
    </div>
  </div>
  
  <div class="info-grid">
    <div class="info-item"><label>Father's Name</label><p><?=escape($student['father_name'])?></p></div>
    <div class="info-item"><label>Phone</label><p><?=escape($student['phone'])?></p></div>
    <div class="info-item"><label>Date of Birth</label><p><?=escape($student['dob'])?></p></div>
    <div class="info-item"><label>Gender</label><p><?=escape($student['gender'])?></p></div>
    <div class="info-item"><label>Admission Date</label><p><?=escape($student['admission_date'])?></p></div>
    <div class="info-item"><label>Monthly Fee</label><p>₨ <?=number_format($student['monthly_fee'])?></p></div>
    <div class="info-item" style="grid-column:1/-1"><label>Address</label><p><?=escape($student['address'])?></p></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
<div class="card">
  <div class="card-title"><i class="fa fa-calendar-check"></i> Attendance — <?=currentMonth()?> <?=currentYear()?></div>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">
    <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);border-radius:10px;padding:14px;text-align:center">
      <div style="font-size:24px;font-weight:800;font-family:'Syne',sans-serif;color:#34d399"><?=$attSummary['present']?></div>
      <div style="font-size:11px;color:var(--text3)">Present</div>
    </div>
    <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:14px;text-align:center">
      <div style="font-size:24px;font-weight:800;font-family:'Syne',sans-serif;color:#f87171"><?=$attSummary['absent']?></div>
      <div style="font-size:11px;color:var(--text3)">Absent</div>
    </div>
    <div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:10px;padding:14px;text-align:center">
      <div style="font-size:24px;font-weight:800;font-family:'Syne',sans-serif;color:#fbbf24"><?=$attSummary['late']?></div>
      <div style="font-size:11px;color:var(--text3)">Late</div>
    </div>
  </div>
  <div style="font-size:13px;color:var(--text2);margin-bottom:6px">Attendance Rate: <strong style="color:var(--accent3)"><?=$attPct?>%</strong></div>
  <div class="progress-bar-wrap"><div class="progress-bar" style="width:<?=$attPct?>%;background:<?=$attPct>=75?'var(--accent3)':($attPct>=50?'var(--warning)':'var(--danger)')?>"></div></div>
</div>

<div class="card">
  <div class="card-title"><i class="fa fa-dollar-sign"></i> Fees — <?=currentYear()?></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
    <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);border-radius:10px;padding:14px;text-align:center">
      <div style="font-size:20px;font-weight:800;font-family:'Syne',sans-serif;color:#34d399">₨ <?=number_format($yearFeesPaid??0)?></div>
      <div style="font-size:11px;color:var(--text3)">Collected</div>
    </div>
    <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:14px;text-align:center">
      <div style="font-size:20px;font-weight:800;font-family:'Syne',sans-serif;color:#f87171">₨ <?=number_format(($yearFeesTotal??0)-($yearFeesPaid??0))?></div>
      <div style="font-size:11px;color:var(--text3)">Pending</div>
    </div>
  </div>
  <?php $feePct=$yearFeesTotal>0?round($yearFeesPaid/$yearFeesTotal*100):0; ?>
  <div style="font-size:13px;color:var(--text2);margin-bottom:6px">Collection Rate: <strong style="color:var(--accent3)"><?=$feePct?>%</strong></div>
  <div class="progress-bar-wrap"><div class="progress-bar" style="width:<?=$feePct?>%"></div></div>
  <div style="margin-top:12px"><a href="?action=student_fees&id=<?=$sid?>" class="btn btn-success btn-sm"><i class="fa fa-file-invoice-dollar"></i> View All Months</a></div>
</div>
</div>

<!-- Edit Student Modal -->
<div class="modal-overlay" id="editStudentModal">
<div class="modal" style="max-width:680px">
  <div class="modal-title"><i class="fa fa-pen"></i> Edit Student</div>
  <form method="POST" action="?action=edit_student">
    <input type="hidden" name="student_id" value="<?=$sid?>">
    <div class="form-grid" style="grid-template-columns:repeat(2,1fr)">
      <div class="form-group"><label>Full Name *</label><input type="text" name="name" value="<?=escape($student['name'])?>" required></div>
      <div class="form-group"><label>Father's Name</label><input type="text" name="father_name" value="<?=escape($student['father_name'])?>"></div>
      <div class="form-group"><label>Roll Number</label><input type="text" name="roll_no" value="<?=escape($student['roll_no'])?>"></div>
      <div class="form-group"><label>Gender</label>
        <select name="gender">
          <option value="Male" <?=$student['gender']==='Male'?'selected':''?>>Male</option>
          <option value="Female" <?=$student['gender']==='Female'?'selected':''?>>Female</option>
          <option value="Other" <?=$student['gender']==='Other'?'selected':''?>>Other</option>
        </select>
      </div>
      <div class="form-group"><label>Date of Birth</label><input type="date" name="dob" value="<?=escape($student['dob'])?>"></div>
      <div class="form-group"><label>Phone</label><input type="text" name="phone" value="<?=escape($student['phone'])?>"></div>
      <div class="form-group" style="grid-column:1/-1"><label>Address</label><input type="text" name="address" value="<?=escape($student['address'])?>"></div>
      <div class="form-group"><label>Class</label>
        <select name="class_id">
          <?php $cr=getClasses($db); while($c=$cr->fetchArray(SQLITE3_ASSOC)): ?>
          <option value="<?=$c['id']?>" <?=$c['id']==$student['class_id']?'selected':''?>><?=escape($c['name'])?> <?=escape($c['section'])?></option>
          <?php endwhile; ?>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="document.getElementById('editStudentModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Update</button>
    </div>
  </form>
</div>
</div>
<?php } }

// ---- STUDENT FEES ----
elseif($action==='student_fees' && isset($_GET['id'])){
    $sid=intval($_GET['id']);
    $student=$db->querySingle("SELECT s.*,c.name as class_name,c.section,c.monthly_fee FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.id=$sid",true);
    if(!$student){ echo "<div class='alert alert-error'>Student not found</div>"; }
    else {
    $selYear=$_GET['year']??currentYear();
    $fees=$db->query("SELECT * FROM fees WHERE student_id=$sid AND year=$selYear ORDER BY CASE month WHEN 'January' THEN 1 WHEN 'February' THEN 2 WHEN 'March' THEN 3 WHEN 'April' THEN 4 WHEN 'May' THEN 5 WHEN 'June' THEN 6 WHEN 'July' THEN 7 WHEN 'August' THEN 8 WHEN 'September' THEN 9 WHEN 'October' THEN 10 WHEN 'November' THEN 11 WHEN 'December' THEN 12 END");
    $feeArr=[]; while($f=$fees->fetchArray(SQLITE3_ASSOC)) $feeArr[$f['month']]=$f;
    $totalPaid=$db->querySingle("SELECT SUM(amount) FROM fees WHERE student_id=$sid AND year=$selYear AND status='paid'");
    $totalAmount=$db->querySingle("SELECT SUM(amount) FROM fees WHERE student_id=$sid AND year=$selYear");
?>
<div class="breadcrumb">
  <a href="?action=students"><i class="fa fa-users"></i> Students</a><span>/</span>
  <a href="?action=student_detail&id=<?=$sid?>"><?=escape($student['name'])?></a><span>/</span>
  <span>Fees</span>
</div>

<div class="card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
  <div>
    <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:700"><?=escape($student['name'])?> — Fee Record</div>
    <div style="color:var(--text2);font-size:13px"><?=escape($student['class_name'])?> <?=escape($student['section'])?> | Roll No: <?=escape($student['roll_no'])?></div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap" class="no-print">
    <form method="GET" style="display:flex;gap:8px">
      <input type="hidden" name="action" value="student_fees">
      <input type="hidden" name="id" value="<?=$sid?>">
      <select name="year" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 10px;color:var(--text);font-size:13px">
        <?php for($y=date('Y')-2;$y<=date('Y')+1;$y++): ?>
        <option value="<?=$y?>" <?=$y==$selYear?'selected':''?>><?=$y?></option>
        <?php endfor; ?>
      </select>
      <button type="submit" class="btn btn-outline btn-sm"><i class="fa fa-search"></i></button>
    </form>
    <button onclick="window.print()" class="btn btn-outline btn-sm"><i class="fa fa-print"></i> Print</button>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:1px">Total Fee</div>
    <div style="font-size:28px;font-weight:800;font-family:'Syne',sans-serif;color:var(--accent);margin:4px 0">₨ <?=number_format($totalAmount??0)?></div>
    <div style="font-size:12px;color:var(--text3)"><?=$selYear?></div>
  </div>
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:1px">Paid</div>
    <div style="font-size:28px;font-weight:800;font-family:'Syne',sans-serif;color:var(--accent3);margin:4px 0">₨ <?=number_format($totalPaid??0)?></div>
  </div>
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:1px">Pending</div>
    <div style="font-size:28px;font-weight:800;font-family:'Syne',sans-serif;color:var(--danger);margin:4px 0">₨ <?=number_format(($totalAmount??0)-($totalPaid??0))?></div>
  </div>
</div>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
    <div class="card-title" style="margin:0"><i class="fa fa-calendar"></i> Monthly Fee Details — <?=$selYear?></div>
  </div>
  <div class="fees-grid">
  <?php foreach(months() as $m):
      $f=$feeArr[$m]??null;
      $isPaid=$f&&$f['status']==='paid';
      $isCurrent=$m===currentMonth()&&$selYear==currentYear();
      $isFuture=strtotime("$m 1 $selYear")>strtotime("1 ".currentMonth()." ".currentYear());
  ?>
  <div class="fee-month-card <?=$isPaid?'paid':''?> <?=$isCurrent?'current-month':''?>">
    <div class="fee-month-name"><?=$m?></div>
    <div class="fee-amount">₨ <?=number_format($f['amount']??$student['monthly_fee'])?></div>
    <div style="margin:8px 0">
      <span class="status <?=$isPaid?'status-paid':'status-unpaid'?>"><?=$isPaid?'Paid':'Unpaid'?></span>
    </div>
    <?php if($isPaid): ?>
    <div class="fee-date"><i class="fa fa-check"></i> <?=date('d M Y',strtotime($f['paid_date']))?></div>
    <?php elseif(!$isFuture): ?>
    <a href="?action=pay_fee&sid=<?=$sid?>&month=<?=$m?>&year=<?=$selYear?>" onclick="return confirm('Mark <?=$m?> fee as paid?')" class="btn btn-success btn-xs" style="width:100%;justify-content:center;margin-top:6px"><i class="fa fa-check"></i> Pay Now</a>
    <?php else: ?>
    <div class="fee-date" style="color:var(--text3)">Not due yet</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
</div>

<!-- Print-friendly table -->
<div class="card" style="margin-top:20px">
  <div class="card-title"><i class="fa fa-table"></i> Fee Summary Table</div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Month</th><th>Amount</th><th>Status</th><th>Paid On</th></tr></thead>
    <tbody>
    <?php foreach(months() as $m):
        $f=$feeArr[$m]??null;
        $isPaid=$f&&$f['status']==='paid';
    ?>
    <tr>
      <td><?=$m?></td>
      <td>₨ <?=number_format($f['amount']??$student['monthly_fee'])?></td>
      <td><span class="status <?=$isPaid?'status-paid':'status-unpaid'?>"><?=$isPaid?'Paid':'Unpaid'?></span></td>
      <td style="font-size:12px;color:var(--text3)"><?=$f&&$f['paid_date']?date('d M Y',strtotime($f['paid_date'])):'—'?></td>
    </tr>
    <?php endforeach; ?>
    <tr style="font-weight:700;background:var(--surface2)">
      <td>Total</td>
      <td>₨ <?=number_format($totalAmount??0)?></td>
      <td><span class="status status-paid">₨ <?=number_format($totalPaid??0)?> Paid</span></td>
      <td><span class="status status-unpaid">₨ <?=number_format(($totalAmount??0)-($totalPaid??0))?> Pending</span></td>
    </tr>
    </tbody>
  </table>
  </div>
</div>
<?php } }

// ---- FEE MANAGEMENT ----
elseif($action==='fees'){
    $curMonth=currentMonth(); $curYear=currentYear();
    $selMonth=$_GET['month']??$curMonth;
    $selYear=$_GET['year']??$curYear;
    $filterClass=$_GET['class_id']??'';
?>
<div class="card" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
  <div class="card-title" style="margin:0"><i class="fa fa-dollar-sign"></i> Fee Management</div>
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto">
    <input type="hidden" name="action" value="fees">
    <select name="month" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:13px">
      <?php foreach(months() as $m): ?>
      <option value="<?=$m?>" <?=$m==$selMonth?'selected':''?>><?=$m?></option>
      <?php endforeach; ?>
    </select>
    <select name="year" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:13px">
      <?php for($y=date('Y')-1;$y<=date('Y')+1;$y++): ?>
      <option value="<?=$y?>" <?=$y==$selYear?'selected':''?>><?=$y?></option>
      <?php endfor; ?>
    </select>
    <select name="class_id" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:13px">
      <option value="">All Classes</option>
      <?php $cr=getClasses($db); while($c=$cr->fetchArray(SQLITE3_ASSOC)): ?>
      <option value="<?=$c['id']?>" <?=$filterClass==$c['id']?'selected':''?>><?=escape($c['name'])?> <?=escape($c['section'])?></option>
      <?php endwhile; ?>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i></button>
    <button type="button" onclick="window.print()" class="btn btn-outline"><i class="fa fa-print"></i></button>
  </form>
</div>

<?php
$where="s.status='active'";
if($filterClass) $where.=" AND s.class_id=".intval($filterClass);
$res=$db->query("SELECT s.*,c.name as class_name,c.section,c.monthly_fee,f.id as fee_id,f.status as fee_status,f.paid_date,f.amount as fee_amount FROM students s LEFT JOIN classes c ON c.id=s.class_id LEFT JOIN fees f ON f.student_id=s.id AND f.month='$selMonth' AND f.year=$selYear WHERE $where ORDER BY c.name,c.section,s.roll_no");
$rows=[];
while($r=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=$r;
$paid=array_filter($rows,fn($r)=>($r['fee_status']??'unpaid')==='paid');
$unpaid=array_filter($rows,fn($r)=>($r['fee_status']??'unpaid')==='unpaid');
$totalCol=array_sum(array_column($paid,'fee_amount'));
?>

<div class="stats" style="grid-template-columns:repeat(4,1fr)">
  <div class="stat-card"><div class="stat-icon blue"><i class="fa fa-users"></i></div><div><div class="stat-value"><?=count($rows)?></div><div class="stat-label">Total Students</div></div></div>
  <div class="stat-card"><div class="stat-icon green"><i class="fa fa-check-circle"></i></div><div><div class="stat-value"><?=count($paid)?></div><div class="stat-label">Paid</div></div></div>
  <div class="stat-card"><div class="stat-icon red"><i class="fa fa-exclamation-circle"></i></div><div><div class="stat-value"><?=count($unpaid)?></div><div class="stat-label">Unpaid</div></div></div>
  <div class="stat-card"><div class="stat-icon orange"><i class="fa fa-dollar-sign"></i></div><div><div class="stat-value">₨ <?=number_format($totalCol)?></div><div class="stat-label">Collected</div></div></div>
</div>

<div class="card">
  <div class="card-title"><i class="fa fa-list"></i> <?=$selMonth?> <?=$selYear?> — Fee Records</div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Roll No</th><th>Name</th><th>Class</th><th>Fee Amount</th><th>Status</th><th>Paid On</th><th class="no-print">Action</th></tr></thead>
    <tbody>
    <?php if(!$rows): ?>
    <tr class="empty-row"><td colspan="8">No records found</td></tr>
    <?php else: foreach($rows as $i=>$r):
        $isPaid=($r['fee_status']??'unpaid')==='paid';
    ?>
    <tr>
      <td style="color:var(--text3)"><?=$i+1?></td>
      <td><?=escape($r['roll_no'])?></td>
      <td><a href="?action=student_detail&id=<?=$r['id']?>" style="color:var(--accent);font-weight:600;text-decoration:none"><?=escape($r['name'])?></a></td>
      <td><a href="?action=class_detail&id=<?=$r['class_id']?>" style="color:var(--text2);text-decoration:none"><?=escape($r['class_name'])?> <?=escape($r['section'])?></a></td>
      <td><strong>₨ <?=number_format($r['fee_amount']??$r['monthly_fee'])?></strong></td>
      <td><span class="status <?=$isPaid?'status-paid':'status-unpaid'?>"><?=$isPaid?'Paid':'Unpaid'?></span></td>
      <td style="font-size:12px;color:var(--text3)"><?=$r['paid_date']?date('d M Y',strtotime($r['paid_date'])):'—'?></td>
      <td class="no-print">
        <?php if(!$isPaid): ?>
        <a href="?action=pay_fee&sid=<?=$r['id']?>&month=<?=$selMonth?>&year=<?=$selYear?>" onclick="return confirm('Pay fee?')" class="btn btn-xs btn-success"><i class="fa fa-check"></i> Pay</a>
        <?php else: ?>
        <span style="color:var(--accent3);font-size:12px"><i class="fa fa-check-circle"></i></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php
}

// ---- FEE REPORT ----
elseif($action==='fee_report'){
    $selYear=$_GET['year']??currentYear();
?>
<div class="card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
  <div class="card-title" style="margin:0"><i class="fa fa-file-invoice-dollar"></i> Annual Fee Report</div>
  <div style="display:flex;gap:8px;align-items:center" class="no-print">
    <form method="GET" style="display:flex;gap:8px">
      <input type="hidden" name="action" value="fee_report">
      <select name="year" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 12px;color:var(--text);font-size:13px">
        <?php for($y=date('Y')-2;$y<=date('Y')+1;$y++): ?>
        <option value="<?=$y?>" <?=$y==$selYear?'selected':''?>><?=$y?></option>
        <?php endfor; ?>
      </select>
      <button type="submit" class="btn btn-outline btn-sm"><i class="fa fa-search"></i></button>
    </form>
    <button onclick="window.print()" class="btn btn-outline btn-sm"><i class="fa fa-print"></i> Print Report</button>
  </div>
</div>

<div class="card">
  <div class="card-title"><i class="fa fa-chart-bar"></i> Monthly Collection — <?=$selYear?></div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Month</th><th>Total Students</th><th>Paid</th><th>Unpaid</th><th>Amount Collected</th><th>Amount Pending</th><th>Rate</th></tr></thead>
    <tbody>
    <?php
    $grandPaid=0; $grandPending=0;
    foreach(months() as $m):
        $totalSt=$db->querySingle("SELECT COUNT(*) FROM students WHERE status='active'");
        $paid=$db->querySingle("SELECT COUNT(*) FROM fees WHERE month='$m' AND year=$selYear AND status='paid'");
        $unpaid=$db->querySingle("SELECT COUNT(*) FROM fees WHERE month='$m' AND year=$selYear AND status='unpaid'");
        $col=$db->querySingle("SELECT SUM(amount) FROM fees WHERE month='$m' AND year=$selYear AND status='paid'")??0;
        $pend=$db->querySingle("SELECT SUM(amount) FROM fees WHERE month='$m' AND year=$selYear AND status='unpaid'")??0;
        $grandPaid+=$col; $grandPending+=$pend;
        $rate=($paid+$unpaid)>0?round($paid/($paid+$unpaid)*100):0;
    ?>
    <tr>
      <td><strong><?=$m?></strong></td>
      <td><?=$paid+$unpaid?></td>
      <td><span class="status status-paid"><?=$paid?></span></td>
      <td><span class="status status-unpaid"><?=$unpaid?></span></td>
      <td style="color:var(--accent3);font-weight:600">₨ <?=number_format($col)?></td>
      <td style="color:var(--danger)">₨ <?=number_format($pend)?></td>
      <td>
        <div style="display:flex;align-items:center;gap:6px">
          <div class="progress-bar-wrap" style="flex:1"><div class="progress-bar" style="width:<?=$rate?>%"></div></div>
          <span style="font-size:12px"><?=$rate?>%</span>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:var(--surface2);font-weight:700;font-size:15px">
      <td colspan="4">Grand Total</td>
      <td style="color:var(--accent3)">₨ <?=number_format($grandPaid)?></td>
      <td style="color:var(--danger)">₨ <?=number_format($grandPending)?></td>
      <td></td>
    </tr>
    </tbody>
  </table>
  </div>
</div>
<?php
}

// ---- ATTENDANCE PAGE ----
elseif($action==='attendance'){
    $filterClass=$_GET['class_id']??'';
    $attDate=$_GET['att_date']??date('Y-m-d');
?>
<div class="card" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
  <div class="card-title" style="margin:0"><i class="fa fa-calendar-check"></i> Daily Attendance</div>
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto">
    <input type="hidden" name="action" value="attendance">
    <input type="date" name="att_date" value="<?=$attDate?>" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:13px">
    <select name="class_id" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:13px">
      <option value="">Select Class</option>
      <?php $cr=getClasses($db); while($c=$cr->fetchArray(SQLITE3_ASSOC)): ?>
      <option value="<?=$c['id']?>" <?=$filterClass==$c['id']?'selected':''?>><?=escape($c['name'])?> <?=escape($c['section'])?></option>
      <?php endwhile; ?>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Load</button>
  </form>
</div>

<?php if($filterClass):
    $class=getClassById($db,$filterClass);
    $students=$db->query("SELECT * FROM students WHERE class_id=$filterClass AND status='active' ORDER BY roll_no,name");
    $studentArr=[];
    while($s=$students->fetchArray(SQLITE3_ASSOC)) $studentArr[]=$s;
?>
<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <div class="card-title" style="margin:0"><i class="fa fa-school"></i> <?=escape($class['name'])?> <?=escape($class['section'])?> — <?=date('d F Y',strtotime($attDate))?></div>
    <div style="display:flex;gap:8px" class="no-print">
      <button type="button" onclick="markAll('present')" class="btn btn-success btn-sm"><i class="fa fa-check"></i> All Present</button>
      <button type="button" onclick="markAll('absent')" class="btn btn-danger btn-sm"><i class="fa fa-times"></i> All Absent</button>
    </div>
  </div>
  <?php if(!$studentArr): ?>
  <div style="text-align:center;padding:40px;color:var(--text3)">No students in this class.</div>
  <?php else: ?>
  <form method="POST" action="?action=mark_attendance">
    <input type="hidden" name="class_id" value="<?=$filterClass?>">
    <input type="hidden" name="att_date" value="<?=$attDate?>">
    <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Roll No</th><th>Name</th><th>Father</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach($studentArr as $i=>$s):
          $existing=getAttendance($db,$s['id'],$attDate);
          $curStatus=$existing?$existing['status']:'present';
      ?>
      <tr>
        <td style="color:var(--text3)"><?=$i+1?></td>
        <td><?=escape($s['roll_no'])?></td>
        <td style="font-weight:600"><?=escape($s['name'])?></td>
        <td style="color:var(--text2)"><?=escape($s['father_name'])?></td>
        <td>
          <div style="display:flex;gap:6px">
            <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:6px 12px;border-radius:7px;border:1px solid var(--border);font-size:13px;transition:.2s" onclick="this.style.background='rgba(16,185,129,.2)'">
              <input type="radio" name="attendance[<?=$s['id']?>]" value="present" <?=$curStatus==='present'?'checked':''?> style="accent-color:var(--accent3)"> Present
            </label>
            <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:6px 12px;border-radius:7px;border:1px solid var(--border);font-size:13px">
              <input type="radio" name="attendance[<?=$s['id']?>]" value="absent" <?=$curStatus==='absent'?'checked':''?> style="accent-color:var(--danger)"> Absent
            </label>
            <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:6px 12px;border-radius:7px;border:1px solid var(--border);font-size:13px">
              <input type="radio" name="attendance[<?=$s['id']?>]" value="late" <?=$curStatus==='late'?'checked':''?> style="accent-color:var(--warning)"> Late
            </label>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div style="margin-top:16px;display:flex;justify-content:flex-end">
      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Attendance</button>
    </div>
  </form>
  <script>
  function markAll(status){
    document.querySelectorAll('input[type="radio"][value="'+status+'"]').forEach(r=>r.checked=true);
  }
  </script>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card" style="text-align:center;padding:48px;color:var(--text3)">
  <i class="fa fa-calendar-check" style="font-size:40px;margin-bottom:12px;display:block;color:var(--accent)"></i>
  <p>Select a class above to mark attendance for <?=date('d F Y',strtotime($attDate))?>.</p>
</div>
<?php endif;
}

// ---- ATTENDANCE REPORT ----
elseif($action==='attendance_report'){
    $selMonth=$_GET['month']??currentMonth();
    $selYear=$_GET['year']??currentYear();
    $filterClass=$_GET['class_id']??'';
?>
<div class="card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
  <div class="card-title" style="margin:0"><i class="fa fa-chart-bar"></i> Attendance Report</div>
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="action" value="attendance_report">
    <select name="month" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 12px;color:var(--text);font-size:13px">
      <?php foreach(months() as $m): ?>
      <option value="<?=$m?>" <?=$m==$selMonth?'selected':''?>><?=$m?></option>
      <?php endforeach; ?>
    </select>
    <select name="year" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 12px;color:var(--text);font-size:13px">
      <?php for($y=date('Y')-1;$y<=date('Y')+1;$y++): ?>
      <option value="<?=$y?>" <?=$y==$selYear?'selected':''?>><?=$y?></option>
      <?php endfor; ?>
    </select>
    <select name="class_id" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 12px;color:var(--text);font-size:13px">
      <option value="">All Classes</option>
      <?php $cr=getClasses($db); while($c=$cr->fetchArray(SQLITE3_ASSOC)): ?>
      <option value="<?=$c['id']?>" <?=$filterClass==$c['id']?'selected':''?>><?=escape($c['name'])?> <?=escape($c['section'])?></option>
      <?php endwhile; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-search"></i></button>
    <button type="button" onclick="window.print()" class="btn btn-outline btn-sm no-print"><i class="fa fa-print"></i></button>
  </form>
</div>

<div class="card">
  <div class="card-title"><i class="fa fa-users"></i> Student Attendance — <?=$selMonth?> <?=$selYear?></div>
  <?php
  $where="s.status='active'";
  if($filterClass) $where.=" AND s.class_id=".intval($filterClass);
  $res=$db->query("SELECT s.*,c.name as class_name,c.section FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE $where ORDER BY c.name,c.section,s.roll_no");
  $rows=[];
  while($r=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=$r;
  ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Roll No</th><th>Name</th><th>Class</th><th>Present</th><th>Absent</th><th>Late</th><th>Total Days</th><th>Attendance %</th></tr></thead>
    <tbody>
    <?php if(!$rows): ?>
    <tr class="empty-row"><td colspan="9">No data found</td></tr>
    <?php else: foreach($rows as $i=>$s):
        $sum=getAttendanceSummary($db,$s['id'],$selMonth,$selYear);
        $total=$sum['present']+$sum['absent']+$sum['late'];
        $pct=$total>0?round($sum['present']/$total*100):0;
    ?>
    <tr>
      <td style="color:var(--text3)"><?=$i+1?></td>
      <td><?=escape($s['roll_no'])?></td>
      <td><a href="?action=student_detail&id=<?=$s['id']?>" style="color:var(--accent);font-weight:600;text-decoration:none"><?=escape($s['name'])?></a></td>
      <td><?=escape($s['class_name'])?> <?=escape($s['section'])?></td>
      <td><span class="status status-present"><?=$sum['present']?></span></td>
      <td><span class="status status-absent"><?=$sum['absent']?></span></td>
      <td><span class="status status-late"><?=$sum['late']?></span></td>
      <td><?=$total?></td>
      <td>
        <div style="display:flex;align-items:center;gap:6px;min-width:120px">
          <div class="progress-bar-wrap" style="flex:1"><div class="progress-bar" style="width:<?=$pct?>%;background:<?=$pct>=75?'var(--accent3)':($pct>=50?'var(--warning)':'var(--danger)')?>"></div></div>
          <span style="font-size:12px;min-width:32px"><?=$pct?>%</span>
        </div>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php
}

// ---- PROMOTIONS ----
elseif($action==='promotions'){
?>
<div class="card">
  <div class="card-title"><i class="fa fa-level-up-alt"></i> Promotion History</div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Student</th><th>From Class</th><th>To Class</th><th>Date</th><th>Year</th></tr></thead>
    <tbody>
    <?php
    $res=$db->query("SELECT p.*,s.name as sname,fc.name as fname,fc.section as fsec,tc.name as tname,tc.section as tsec FROM promotions p JOIN students s ON s.id=p.student_id LEFT JOIN classes fc ON fc.id=p.from_class_id LEFT JOIN classes tc ON tc.id=p.to_class_id ORDER BY p.id DESC");
    $hasRows=false;
    $i=1;
    while($r=$res->fetchArray(SQLITE3_ASSOC)){
        $hasRows=true;
        echo "<tr>
        <td style='color:var(--text3)'>$i</td>
        <td><a href='?action=student_detail&id={$r['student_id']}' style='color:var(--accent);font-weight:600;text-decoration:none'>".escape($r['sname'])."</a></td>
        <td>".escape($r['fname'].' '.$r['fsec'])."</td>
        <td><span style='color:var(--accent3);font-weight:600'>".escape($r['tname'].' '.$r['tsec'])."</span></td>
        <td style='font-size:13px'>".escape($r['promoted_date'])."</td>
        <td>".escape($r['academic_year'])."</td>
        </tr>";
        $i++;
    }
    if(!$hasRows) echo "<tr class='empty-row'><td colspan='6'><i class='fa fa-level-up-alt' style='font-size:28px;margin-bottom:8px;display:block;color:var(--text3)'></i>No promotions recorded yet</td></tr>";
    ?>
    </tbody>
  </table>
  </div>
</div>
<?php
}

// ---- SETTINGS ----
elseif($action==='settings'){
?>
<div class="card">
  <div class="card-title"><i class="fa fa-cog"></i> System Settings</div>
  <div class="info-grid">
    <div class="info-item"><label>PHP Version</label><p><?=phpversion()?></p></div>
    <div class="info-item"><label>SQLite Version</label><p><?=SQLite3::version()['versionString']?></p></div>
    <div class="info-item"><label>Database</label><p>school_sms.db (SQLite)</p></div>
    <div class="info-item"><label>Current Date</label><p><?=date('d M Y')?></p></div>
    <div class="info-item"><label>Total Classes</label><p><?=$db->querySingle("SELECT COUNT(*) FROM classes")?></p></div>
    <div class="info-item"><label>Total Students</label><p><?=$db->querySingle("SELECT COUNT(*) FROM students WHERE status='active'")?></p></div>
    <div class="info-item"><label>Total Fee Records</label><p><?=$db->querySingle("SELECT COUNT(*) FROM fees")?></p></div>
    <div class="info-item"><label>Total Attendance Records</label><p><?=$db->querySingle("SELECT COUNT(*) FROM attendance")?></p></div>
    <div class="info-item"><label>Software Developer</label><p>Shakeel Ahmad Jaan</p></div>
  </div>
</div>

<div class="card" style="border-color:rgba(239,68,68,.3)">
  <div class="card-title" style="color:var(--danger)"><i class="fa fa-triangle-exclamation"></i> Danger Zone</div>
  <p style="color:var(--text2);font-size:14px;margin-bottom:16px">These actions are permanent and cannot be undone. Please be careful.</p>
  <div style="display:flex;gap:12px;flex-wrap:wrap">
    <a href="?action=reset_attendance" onclick="return confirm('Delete ALL attendance records? This cannot be undone!')" class="btn btn-danger btn-sm"><i class="fa fa-trash"></i> Clear All Attendance</a>
    <a href="?action=reset_promotions" onclick="return confirm('Delete ALL promotion records?')" class="btn btn-danger btn-sm"><i class="fa fa-trash"></i> Clear Promotions History</a>
  </div>
</div>

<div class="card">
  <div class="card-title"><i class="fa fa-circle-info"></i> How to Use</div>
  <div style="font-size:14px;color:var(--text2);line-height:1.8">
    <p><strong style="color:var(--text)">1. Add Classes</strong> — Go to Classes and add your class sections (e.g. Class 1-A, Class 1-B).</p>
    <p><strong style="color:var(--text)">2. Add Students</strong> — Click on a class, then "Add Student" to enroll students. Fee records are auto-generated.</p>
    <p><strong style="color:var(--text)">3. Mark Attendance</strong> — Go to a class → Attendance tab, or use the Attendance menu.</p>
    <p><strong style="color:var(--text)">4. Collect Fees</strong> — Go to a student's Fees page or use Fee Management. Click "Pay Fee" to mark as paid. The button auto-hides when paid and shows again next month.</p>
    <p><strong style="color:var(--text)">5. Promote Students</strong> — Click "Promote" on any student or "Promote All" in the class header to move students to the next class.</p>
    <p><strong style="color:var(--text)">6. Print/PDF</strong> — Use the Print button on any page. Use Ctrl+P → Save as PDF in your browser.</p>
  </div>
</div>
<?php
}

// Reset actions
if($action==='reset_attendance'){
    $db->exec("DELETE FROM attendance");
    header("Location: index.php?action=settings&msg=Attendance+cleared&type=success"); exit;
}
if($action==='reset_promotions'){
    $db->exec("DELETE FROM promotions");
    header("Location: index.php?action=settings&msg=Promotions+cleared&type=success"); exit;
}
?>

</div><!-- end content -->
</div><!-- end main -->

<!-- Close modals on overlay click -->
<script>
document.querySelectorAll('.modal-overlay').forEach(o=>{
  o.addEventListener('click',e=>{ if(e.target===o) o.classList.remove('active'); });
});
// Live clock
function updateClock(){
  const now=new Date();
  document.getElementById('clock').textContent=now.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(updateClock,1000); updateClock();
</script>

</body>
</html>
