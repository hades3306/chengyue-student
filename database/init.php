<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

$isDirectCli = PHP_SAPI === 'cli'
    && isset($_SERVER['argv'][0])
    && realpath($_SERVER['argv'][0]) === realpath(__FILE__);

$dbPath = ROOT_PATH . '/database/chengyue-campus.sqlite';
$uploadBase = ROOT_PATH . '/public/cyedu-uploads';
$storagePath = ROOT_PATH . '/storage';

foreach ([
    dirname($dbPath),
    $uploadBase . '/avatars',
    $uploadBase . '/admissions',
    $storagePath . '/reports',
    $storagePath . '/archives',
    $storagePath . '/exports',
] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

$seedReports = [
    '2026-11-enrollment.csv' => "院系,专业,班级数,在籍学生,在读学生\n信息工程学院,计算机科学与技术,1,5,5\n信息工程学院,大数据技术,1,5,5\n信息工程学院,人工智能技术应用,1,5,5\n数字艺术学院,数字媒体技术,1,5,5\n教育学院,学前教育,1,5,5\n经济管理学院,信息管理与信息系统,1,5,4\n",
    '2026-11-attendance.csv' => "班级,应到人次,实到人次,出勤率\n计科2401,15,15,100.0%\n数媒2401,15,15,100.0%\n大数据2501,15,15,100.0%\n人工智能2501,15,15,100.0%\n学前2501,15,15,100.0%\n信管2401,15,15,100.0%\n",
    '2026-11-tuition.csv' => "学期,应收金额,实收金额,收缴率\n2026-2027-1,224000.00,171600.00,76.6%\n",
];
foreach ($seedReports as $file => $content) {
    $target = $storagePath . '/reports/' . $file;
    if (!is_file($target)) {
        file_put_contents($target, $content);
    }
}

if (is_file($dbPath)) {
    if ($isDirectCli) {
        echo "数据库已存在：{$dbPath}\n";
    }
    return;
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

$insert = static function (string $table, array $row) use ($pdo): int {
    $columns = array_keys($row);
    $placeholders = array_map(static fn (string $col): string => ':' . $col, $columns);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($row);
    return (int) $pdo->lastInsertId();
};

$pdo->beginTransaction();

$users = [
    ['username' => 'admin', 'password' => 'Chengyue@2026', 'real_name' => '陈砚清', 'role' => '系统管理员', 'dept' => '信息中心', 'created_at' => '2024-11-18 09:00:00'],
    ['username' => 'registrar', 'password' => 'Campus@2026', 'real_name' => '苏婉宁', 'role' => '教务管理员', 'dept' => '教务处', 'created_at' => '2025-02-24 10:20:00'],
];
foreach ($users as $user) {
    $insert('users', [
        'username' => $user['username'],
        'password_hash' => password_hash($user['password'], PASSWORD_DEFAULT),
        'real_name' => $user['real_name'],
        'role' => $user['role'],
        'dept' => $user['dept'],
        'status' => '启用',
        'last_login_at' => null,
        'created_at' => $user['created_at'],
    ]);
}

$departments = [
    ['code' => 'XX', 'name' => '信息工程学院', 'dean' => '罗建平', 'phone' => '0571-8662-3101', 'office' => '明德楼 A302'],
    ['code' => 'YS', 'name' => '数字艺术学院', 'dean' => '白清扬', 'phone' => '0571-8662-3206', 'office' => '弘艺楼 205'],
    ['code' => 'JY', 'name' => '教育学院', 'dean' => '程书岚', 'phone' => '0571-8662-3308', 'office' => '育英楼 108'],
    ['code' => 'JG', 'name' => '经济管理学院', 'dean' => '顾承泽', 'phone' => '0571-8662-3412', 'office' => '明德楼 B401'],
];
$departmentIds = [];
foreach ($departments as $row) {
    $departmentIds[$row['code']] = $insert('departments', $row);
}

$majors = [
    ['code' => 'CS01', 'name' => '计算机科学与技术', 'dept' => 'XX', 'degree' => '工学学士', 'duration_years' => 4, 'status' => '招生中'],
    ['code' => 'BD02', 'name' => '大数据技术', 'dept' => 'XX', 'degree' => '工学学士', 'duration_years' => 4, 'status' => '招生中'],
    ['code' => 'AI03', 'name' => '人工智能技术应用', 'dept' => 'XX', 'degree' => '工学学士', 'duration_years' => 4, 'status' => '招生中'],
    ['code' => 'DM04', 'name' => '数字媒体技术', 'dept' => 'YS', 'degree' => '艺术学学士', 'duration_years' => 4, 'status' => '招生中'],
    ['code' => 'ED05', 'name' => '学前教育', 'dept' => 'JY', 'degree' => '教育学学士', 'duration_years' => 4, 'status' => '招生中'],
    ['code' => 'IM06', 'name' => '信息管理与信息系统', 'dept' => 'JG', 'degree' => '管理学学士', 'duration_years' => 4, 'status' => '招生中'],
];
$majorIds = [];
foreach ($majors as $row) {
    $deptId = $departmentIds[$row['dept']];
    unset($row['dept']);
    $row['department_id'] = $deptId;
    $majorIds[$row['code']] = $insert('majors', $row);
}

$teachers = [
    ['teacher_no' => 'T2015011', 'name' => '张启明', 'gender' => '男', 'title' => '教授', 'dept' => 'XX', 'phone' => '13906512201', 'email' => 'zhang.qiming@chengyue.edu.cn', 'specialty' => '软件工程与算法设计', 'join_date' => '2015-09-01'],
    ['teacher_no' => 'T2016008', 'name' => '李慧兰', 'gender' => '女', 'title' => '副教授', 'dept' => 'XX', 'phone' => '13857123306', 'email' => 'li.huilan@chengyue.edu.cn', 'specialty' => '数据库系统与数据治理', 'join_date' => '2016-03-15'],
    ['teacher_no' => 'T2018006', 'name' => '王振宇', 'gender' => '男', 'title' => '讲师', 'dept' => 'XX', 'phone' => '13758204418', 'email' => 'wang.zhenyu@chengyue.edu.cn', 'specialty' => '数据库应用与信息系统', 'join_date' => '2018-09-01'],
    ['teacher_no' => 'T2019004', 'name' => '赵雅琴', 'gender' => '女', 'title' => '讲师', 'dept' => 'XX', 'phone' => '13605715522', 'email' => 'zhao.yaqin@chengyue.edu.cn', 'specialty' => '计算机网络与信息安全', 'join_date' => '2019-02-20'],
    ['teacher_no' => 'T2017002', 'name' => '陈立坤', 'gender' => '男', 'title' => '副教授', 'dept' => 'YS', 'phone' => '13588106639', 'email' => 'chen.likun@chengyue.edu.cn', 'specialty' => '数字影像与交互设计', 'join_date' => '2017-09-01'],
    ['teacher_no' => 'T2020021', 'name' => '刘思远', 'gender' => '男', 'title' => '讲师', 'dept' => 'XX', 'phone' => '13968027741', 'email' => 'liu.siyuan@chengyue.edu.cn', 'specialty' => '大数据平台与数据工程', 'join_date' => '2020-08-25'],
    ['teacher_no' => 'T2016009', 'name' => '周敏华', 'gender' => '女', 'title' => '副教授', 'dept' => 'XX', 'phone' => '13867418853', 'email' => 'zhou.minhua@chengyue.edu.cn', 'specialty' => '机器学习与智能系统', 'join_date' => '2016-09-01'],
    ['teacher_no' => 'T2015003', 'name' => '吴嘉诚', 'gender' => '男', 'title' => '副教授', 'dept' => 'JY', 'phone' => '13777059964', 'email' => 'wu.jiacheng@chengyue.edu.cn', 'specialty' => '学前教育与儿童发展', 'join_date' => '2015-09-01'],
    ['teacher_no' => 'T2018007', 'name' => '郑晓芳', 'gender' => '女', 'title' => '讲师', 'dept' => 'JY', 'phone' => '13687931075', 'email' => 'zheng.xiaofang@chengyue.edu.cn', 'specialty' => '教育测量与课程研究', 'join_date' => '2018-03-01'],
    ['teacher_no' => 'T2021015', 'name' => '孙博文', 'gender' => '男', 'title' => '讲师', 'dept' => 'JG', 'phone' => '13957172186', 'email' => 'sun.bowen@chengyue.edu.cn', 'specialty' => '信息管理与商务分析', 'join_date' => '2021-09-01'],
    ['teacher_no' => 'T2013001', 'name' => '何静', 'gender' => '女', 'title' => '教授', 'dept' => 'YS', 'phone' => '13506513297', 'email' => 'he.jing@chengyue.edu.cn', 'specialty' => '视觉传达与数字课程资源', 'join_date' => '2013-09-01'],
    ['teacher_no' => 'T2019023', 'name' => '林国栋', 'gender' => '男', 'title' => '讲师', 'dept' => 'JG', 'phone' => '13757194408', 'email' => 'lin.guodong@chengyue.edu.cn', 'specialty' => '电子商务与供应链运营', 'join_date' => '2019-09-01'],
];
$teacherIds = [];
foreach ($teachers as $row) {
    $deptCode = $row['dept'];
    unset($row['dept']);
    $row['department_id'] = $departmentIds[$deptCode];
    $row['status'] = '在职';
    $teacherIds[$row['teacher_no']] = $insert('teachers', $row);
}

$classes = [
    ['class_code' => 'XK2401', 'name' => '2024级计算机科学与技术1班', 'major' => 'CS01', 'grade_year' => 2024, 'counselor' => 'T2016008', 'classroom' => '明德楼 A201', 'capacity' => 42, 'status' => '在读'],
    ['class_code' => 'SM2401', 'name' => '2024级数字媒体技术1班', 'major' => 'DM04', 'grade_year' => 2024, 'counselor' => 'T2017002', 'classroom' => '弘艺楼 B103', 'capacity' => 38, 'status' => '在读'],
    ['class_code' => 'DS2501', 'name' => '2025级大数据技术1班', 'major' => 'BD02', 'grade_year' => 2025, 'counselor' => 'T2020021', 'classroom' => '明德楼 A305', 'capacity' => 45, 'status' => '在读'],
    ['class_code' => 'RG2501', 'name' => '2025级人工智能技术应用1班', 'major' => 'AI03', 'grade_year' => 2025, 'counselor' => 'T2016009', 'classroom' => '明德楼 A307', 'capacity' => 45, 'status' => '在读'],
    ['class_code' => 'XQ2501', 'name' => '2025级学前教育1班', 'major' => 'ED05', 'grade_year' => 2025, 'counselor' => 'T2015003', 'classroom' => '育英楼 C102', 'capacity' => 40, 'status' => '在读'],
    ['class_code' => 'IM2401', 'name' => '2024级信息管理与信息系统1班', 'major' => 'IM06', 'grade_year' => 2024, 'counselor' => 'T2021015', 'classroom' => '明德楼 B402', 'capacity' => 40, 'status' => '在读'],
];
$classIds = [];
foreach ($classes as $row) {
    $majorCode = $row['major'];
    $counselorNo = $row['counselor'];
    unset($row['major'], $row['counselor']);
    $row['major_id'] = $majorIds[$majorCode];
    $row['counselor_id'] = $teacherIds[$counselorNo];
    $classIds[$row['class_code']] = $insert('classes', $row);
}

$studentGroups = [
    'XK2401' => [
        ['2024010101', '沈亦舟', '男', '2006-03-18', '330106200603180017', '13857100101', 'shen.yizhou@stu.chengyue.edu.cn', '杭州市西湖区文三路258号3幢2单元501', '沈国平', '13905712201', '共青团员', '在读'],
        ['2024010102', '许清晏', '女', '2005-11-02', '330105200511020028', '13857100102', 'xu.qingyan@stu.chengyue.edu.cn', '杭州市拱墅区湖墅南路136号5幢301', '许文华', '13805713302', '共青团员', '在读'],
        ['2024010103', '唐嘉禾', '女', '2006-06-25', '330108200606250043', '13857100103', 'tang.jiahe@stu.chengyue.edu.cn', '杭州市滨江区江南大道588号阳光公寓2幢902', '唐建明', '13957124403', '共青团员', '在读'],
        ['2024010104', '周予安', '男', '2006-01-09', '330106200601090036', '13857100104', 'zhou.yuan@stu.chengyue.edu.cn', '杭州市西湖区古墩路701号紫金港小区12幢402', '周立新', '13805715504', '群众', '在读'],
        ['2024010105', '林知夏', '女', '2005-08-14', '330110200508140021', '13857100105', 'lin.zhixia@stu.chengyue.edu.cn', '杭州市余杭区良渚街道古墩路1888号6幢1102', '林海涛', '13958126605', '中共预备党员', '在读'],
    ],
    'SM2401' => [
        ['2024040101', '白若溪', '女', '2006-04-12', '330106200604120045', '13857100201', 'bai.ruoxi@stu.chengyue.edu.cn', '杭州市西湖区留和路299号翰墨香林3幢502', '白景明', '13805717701', '共青团员', '在读'],
        ['2024040102', '江映雪', '女', '2005-10-30', '330105200510300022', '13857100202', 'jiang.yingxue@stu.chengyue.edu.cn', '杭州市拱墅区中山北路632号凤起苑8幢201', '江维远', '13957128802', '共青团员', '在读'],
        ['2024040103', '何泽楷', '男', '2006-08-19', '330108200608190039', '13857100203', 'he.zekai@stu.chengyue.edu.cn', '杭州市滨江区滨盛路1509号海威大厦1幢1806', '何志远', '13805719903', '群众', '在读'],
        ['2024040104', '温子涵', '女', '2006-03-05', '330106200603050018', '13857100204', 'wen.zihan@stu.chengyue.edu.cn', '杭州市西湖区丰潭路380号嘉绿苑7幢603', '温成林', '13958120004', '共青团员', '在读'],
        ['2024040105', '宋以南', '男', '2005-12-22', '330110200512220037', '13857100205', 'song.yinan@stu.chengyue.edu.cn', '杭州市余杭区五常街道文一西路998号2幢401', '宋立群', '13805711105', '共青团员', '在读'],
    ],
    'DS2501' => [
        ['2025020101', '陈屿川', '男', '2007-02-11', '330106200702110033', '13857100301', 'chen.yuchuan@stu.chengyue.edu.cn', '杭州市西湖区教工路18号世贸丽晶城A座1203', '陈建华', '13957122201', '共青团员', '在读'],
        ['2025020102', '蒋明睿', '男', '2007-05-26', '330105200705260041', '13857100302', 'jiang.mingrui@stu.chengyue.edu.cn', '杭州市拱墅区莫干山路972号泰嘉园4幢302', '蒋国梁', '13805713302', '群众', '在读'],
        ['2025020103', '梁思柔', '女', '2007-01-17', '330108200701170026', '13857100303', 'liang.sirou@stu.chengyue.edu.cn', '杭州市滨江区长河路590号江锦苑9幢1601', '梁卫东', '13958124403', '共青团员', '在读'],
        ['2025020104', '高子墨', '男', '2006-11-08', '330106200611080047', '13857100304', 'gao.zimo@stu.chengyue.edu.cn', '杭州市西湖区文一西路75号华星时代广场B座705', '高远山', '13805715504', '共青团员', '在读'],
        ['2025020105', '谢云舒', '女', '2007-04-03', '330110200704030014', '13857100305', 'xie.yunshu@stu.chengyue.edu.cn', '杭州市余杭区仓前街道海曙路88号3幢502', '谢明辉', '13958126605', '共青团员', '在读'],
    ],
    'RG2501' => [
        ['2025030101', '罗景行', '男', '2007-06-14', '330108200706140035', '13857100401', 'luo.jingxing@stu.chengyue.edu.cn', '杭州市滨江区江陵路2028号星耀城2幢1502', '罗承志', '13805717701', '共青团员', '在读'],
        ['2025030102', '韩莹莹', '女', '2007-03-09', '330105200703090029', '13857100402', 'han.yingying@stu.chengyue.edu.cn', '杭州市拱墅区大关路98号大关西六苑4幢601', '韩立群', '13957128802', '群众', '在读'],
        ['2025030103', '曹越', '男', '2006-12-01', '330106200612010031', '13857100403', 'cao.yue@stu.chengyue.edu.cn', '杭州市西湖区申花路796号丽晶湾6幢1801', '曹国平', '13805719903', '共青团员', '在读'],
        ['2025030104', '秦慕言', '女', '2007-07-23', '330110200707230048', '13857100404', 'qin.muyan@stu.chengyue.edu.cn', '杭州市余杭区未来科技城文一西路1818号1幢902', '秦卫平', '13958120004', '共青团员', '在读'],
        ['2025030105', '邓雨桐', '女', '2007-02-27', '330108200702270016', '13857100405', 'deng.yutong@stu.chengyue.edu.cn', '杭州市滨江区江南大道1090号中南公寓5幢1103', '邓志刚', '13805711105', '共青团员', '在读'],
    ],
    'XQ2501' => [
        ['2025050101', '夏沐晴', '女', '2007-05-06', '330106200705060024', '13857100501', 'xia.muqing@stu.chengyue.edu.cn', '杭州市西湖区转塘街道云溪印象3幢602', '夏建民', '13957122201', '共青团员', '在读'],
        ['2025050102', '方念之', '女', '2006-10-19', '330105200610190043', '13857100502', 'fang.nianzhi@stu.chengyue.edu.cn', '杭州市拱墅区香积寺路188号香积公寓6幢801', '方志远', '13805713302', '群众', '在读'],
        ['2025050103', '邹诗涵', '女', '2007-08-12', '330108200708120036', '13857100503', 'zou.shihan@stu.chengyue.edu.cn', '杭州市滨江区西兴街道江汉路1786号钱塘之星2幢705', '邹国栋', '13958124403', '共青团员', '在读'],
        ['2025050104', '汤若琳', '女', '2007-01-25', '330110200701250027', '13857100504', 'tang.ruolin@stu.chengyue.edu.cn', '杭州市余杭区良渚街道玉鸟路18号文化村14幢302', '汤建平', '13805715504', '共青团员', '在读'],
        ['2025050105', '黎安然', '女', '2007-09-04', '330106200709040019', '13857100505', 'li.anran@stu.chengyue.edu.cn', '杭州市西湖区之江路128号梦湖山庄9幢101', '黎海峰', '13958126605', '共青团员', '在读'],
    ],
    'IM2401' => [
        ['2024060101', '程一诺', '男', '2006-09-28', '330108200609280032', '13857100601', 'cheng.yinuo@stu.chengyue.edu.cn', '杭州市滨江区江南大道3900号东冠大厦1幢1602', '程立新', '13805717701', '共青团员', '在读'],
        ['2024060102', '顾明轩', '男', '2006-05-21', '330106200605210038', '13857100602', 'gu.mingxuan@stu.chengyue.edu.cn', '杭州市西湖区文二西路777号西溪名园12幢203', '顾建强', '13957128802', '共青团员', '在读'],
        ['2024060103', '苏念慈', '女', '2005-12-03', '330105200512030041', '13857100603', 'su.nianci@stu.chengyue.edu.cn', '杭州市拱墅区登云路518号锦昌文华苑5幢902', '苏为民', '13805719903', '群众', '休学'],
        ['2024060104', '陆承宇', '男', '2006-07-07', '330110200607070025', '13857100604', 'lu.chengyu@stu.chengyue.edu.cn', '杭州市余杭区五常大道156号翡翠城2幢601', '陆建荣', '13958120004', '共青团员', '在读'],
        ['2024060105', '叶舒窈', '女', '2006-02-16', '330108200602160044', '13857100605', 'ye.shuyao@stu.chengyue.edu.cn', '杭州市滨江区滨康路101号海威中心3幢1201', '叶国强', '13805711105', '共青团员', '在读'],
    ],
];

$classMajorMap = [
    'XK2401' => 'CS01',
    'SM2401' => 'DM04',
    'DS2501' => 'BD02',
    'RG2501' => 'AI03',
    'XQ2501' => 'ED05',
    'IM2401' => 'IM06',
];

$studentIds = [];
$studentIndex = 0;
foreach ($studentGroups as $classCode => $group) {
    foreach ($group as $row) {
        $studentNo = $row[0];
        $studentIds[$studentNo] = $insert('students', [
            'student_no' => $row[0],
            'name' => $row[1],
            'gender' => $row[2],
            'birth_date' => $row[3],
            'id_card' => $row[4],
            'class_id' => $classIds[$classCode],
            'major_id' => $majorIds[$classMajorMap[$classCode]],
            'enrollment_date' => ($classCode === 'DS2501' || $classCode === 'RG2501' || $classCode === 'XQ2501' ? '2025-09-01' : '2024-09-01'),
            'phone' => $row[5],
            'email' => $row[6],
            'address' => $row[7],
            'guardian_name' => $row[8],
            'guardian_phone' => $row[9],
            'status' => $row[11],
            'political_status' => $row[10],
            'avatar' => null,
            'created_at' => ($classCode === 'DS2501' || $classCode === 'RG2501' || $classCode === 'XQ2501' ? '2025-09-02 08:30:00' : '2024-09-02 08:30:00'),
        ]);
        $studentIndex++;
    }
}

$courses = [
    ['course_code' => 'CS201', 'name' => '程序设计基础', 'dept' => 'XX', 'teacher' => 'T2015011', 'credit' => 4, 'hours' => 64, 'course_type' => '必修', 'semester' => '2026-2027-1', 'schedule' => '周一 1-2节 / 周三 3-4节', 'classroom' => '明德楼 A201', 'status' => '开课中'],
    ['course_code' => 'CS205', 'name' => '数据结构与算法', 'dept' => 'XX', 'teacher' => 'T2016008', 'credit' => 4, 'hours' => 64, 'course_type' => '必修', 'semester' => '2026-2027-1', 'schedule' => '周二 1-2节 / 周四 3-4节', 'classroom' => '明德楼 A202', 'status' => '开课中'],
    ['course_code' => 'CS306', 'name' => '数据库原理与应用', 'dept' => 'XX', 'teacher' => 'T2018006', 'credit' => 3, 'hours' => 48, 'course_type' => '必修', 'semester' => '2026-2027-1', 'schedule' => '周三 1-2节 / 周五 3-4节', 'classroom' => '明德楼 A205', 'status' => '开课中'],
    ['course_code' => 'CS312', 'name' => '计算机网络技术', 'dept' => 'XX', 'teacher' => 'T2019004', 'credit' => 3, 'hours' => 48, 'course_type' => '必修', 'semester' => '2026-2027-1', 'schedule' => '周二 3-4节 / 周四 1-2节', 'classroom' => '明德楼 A301', 'status' => '开课中'],
    ['course_code' => 'DM210', 'name' => '数字图像处理', 'dept' => 'YS', 'teacher' => 'T2017002', 'credit' => 3, 'hours' => 48, 'course_type' => '选修', 'semester' => '2026-2027-1', 'schedule' => '周一 3-4节 / 周四 5-6节', 'classroom' => '弘艺楼 B205', 'status' => '开课中'],
    ['course_code' => 'BD208', 'name' => '大数据采集与清洗', 'dept' => 'XX', 'teacher' => 'T2020021', 'credit' => 3, 'hours' => 48, 'course_type' => '必修', 'semester' => '2026-2027-1', 'schedule' => '周一 5-6节 / 周三 3-4节', 'classroom' => '明德楼 A305', 'status' => '开课中'],
    ['course_code' => 'AI301', 'name' => '机器学习导论', 'dept' => 'XX', 'teacher' => 'T2016009', 'credit' => 3, 'hours' => 48, 'course_type' => '选修', 'semester' => '2026-2027-1', 'schedule' => '周二 5-6节 / 周五 1-2节', 'classroom' => '明德楼 A307', 'status' => '开课中'],
    ['course_code' => 'ED204', 'name' => '学前儿童发展心理学', 'dept' => 'JY', 'teacher' => 'T2015003', 'credit' => 3, 'hours' => 48, 'course_type' => '必修', 'semester' => '2026-2027-1', 'schedule' => '周一 1-2节 / 周三 5-6节', 'classroom' => '育英楼 C102', 'status' => '开课中'],
    ['course_code' => 'ED310', 'name' => '教育研究方法', 'dept' => 'JY', 'teacher' => 'T2018007', 'credit' => 2, 'hours' => 32, 'course_type' => '必修', 'semester' => '2026-2027-1', 'schedule' => '周二 3-4节 / 周四 5-6节', 'classroom' => '育英楼 C205', 'status' => '开课中'],
    ['course_code' => 'ED215', 'name' => '现代教育技术', 'dept' => 'JY', 'teacher' => 'T2013001', 'credit' => 2, 'hours' => 32, 'course_type' => '选修', 'semester' => '2026-2027-1', 'schedule' => '周三 1-2节 / 周五 5-6节', 'classroom' => '育英楼 C301', 'status' => '开课中'],
];
$courseIds = [];
foreach ($courses as $row) {
    $deptCode = $row['dept'];
    $teacherNo = $row['teacher'];
    unset($row['dept'], $row['teacher']);
    $row['department_id'] = $departmentIds[$deptCode];
    $row['teacher_id'] = $teacherIds[$teacherNo];
    $courseIds[$row['course_code']] = $insert('courses', $row);
}

$classCourseMap = [
    'XK2401' => ['CS205', 'CS306', 'CS312'],
    'SM2401' => ['DM210', 'CS306', 'CS312'],
    'DS2501' => ['CS201', 'BD208', 'AI301'],
    'RG2501' => ['CS201', 'AI301', 'BD208'],
    'XQ2501' => ['ED204', 'ED310', 'ED215'],
    'IM2401' => ['CS306', 'BD208', 'CS201'],
];

$teacherNameByNo = [];
foreach ($teachers as $t) {
    $teacherNameByNo[$t['teacher_no']] = $t['name'];
}
$courseTeacherNames = [];
$courseExamDates = [];
$examDateSeed = ['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-03', '2026-11-05', '2026-11-04', '2026-11-06', '2026-11-03', '2026-11-05', '2026-11-04'];
$courseCounter = 0;
foreach ($courses as $courseRow) {
    $courseCode = $courseRow['course_code'];
    $courseTeacherNames[$courseCode] = $teacherNameByNo[$courseRow['teacher']];
    $courseExamDates[$courseCode] = $examDateSeed[$courseCounter % count($examDateSeed)];
    $courseCounter++;
}

$gradeStudentIndex = 0;
$classIndex = 0;
foreach ($classCourseMap as $classCode => $courseCodes) {
    $classStudents = [];
    foreach ($studentGroups[$classCode] as $groupRow) {
        $classStudents[] = $studentIds[$groupRow[0]];
    }
    $courseIndex = 0;
    foreach ($courseCodes as $courseCode) {
        foreach ($classStudents as $si => $studentId) {
            $score = 55 + (($gradeStudentIndex * 13 + $courseIndex * 17 + $classIndex * 11) % 44);
            $insert('grades', [
                'student_id' => $studentId,
                'course_id' => $courseIds[$courseCode],
                'semester' => '2026-2027-1',
                'score' => $score,
                'exam_type' => '期中',
                'exam_date' => $courseExamDates[$courseCode],
                'recorder' => $courseTeacherNames[$courseCode],
                'created_at' => $courseExamDates[$courseCode] . ' 17:30:00',
            ]);
            $gradeStudentIndex++;
        }
        $courseIndex++;
    }
    $classIndex++;
}

$attendanceSessions = [
    'XK2401' => [['CS205', '2026-11-03', '第1-2节'], ['CS306', '2026-11-05', '第3-4节'], ['CS312', '2026-11-10', '第1-2节']],
    'SM2401' => [['DM210', '2026-11-02', '第3-4节'], ['CS306', '2026-11-06', '第1-2节'], ['CS312', '2026-11-10', '第3-4节']],
    'DS2501' => [['CS201', '2026-11-02', '第1-2节'], ['BD208', '2026-11-04', '第3-4节'], ['AI301', '2026-11-09', '第5-6节']],
    'RG2501' => [['CS201', '2026-11-03', '第3-4节'], ['AI301', '2026-11-05', '第1-2节'], ['BD208', '2026-11-10', '第3-4节']],
    'XQ2501' => [['ED204', '2026-11-02', '第1-2节'], ['ED310', '2026-11-04', '第3-4节'], ['ED215', '2026-11-09', '第5-6节']],
    'IM2401' => [['CS306', '2026-11-03', '第1-2节'], ['BD208', '2026-11-05', '第3-4节'], ['CS201', '2026-11-06', '第5-6节']],
];
$remarkPool = [
    '迟到' => '晚到 5 分钟',
    '请假' => '病假，已提交请假单',
    '缺勤' => '未到课，已联系辅导员',
];
foreach ($attendanceSessions as $classCode => $sessions) {
    $classStudents = [];
    foreach ($studentGroups[$classCode] as $groupRow) {
        $classStudents[] = $studentIds[$groupRow[0]];
    }
    foreach ($sessions as $sessionIndex => [$courseCode, $classDate, $period]) {
        foreach ($classStudents as $si => $studentId) {
            $statusSeed = crc32($classCode . '|' . $classDate . '|' . $period . '|' . $studentId) % 100;
            if ($statusSeed < 90) {
                $status = '出勤';
            } elseif ($statusSeed < 94) {
                $status = '迟到';
            } elseif ($statusSeed < 98) {
                $status = '请假';
            } else {
                $status = '缺勤';
            }
            $insert('attendance', [
                'student_id' => $studentId,
                'course_id' => $courseIds[$courseCode],
                'class_date' => $classDate,
                'period' => $period,
                'status' => $status,
                'remark' => $remarkPool[$status] ?? null,
                'recorder' => $courseTeacherNames[$courseCode],
                'created_at' => $classDate . ' 09:10:00',
            ]);
        }
    }
}

$tuitionAmounts = ['CS01' => 7800, 'DM04' => 8200, 'BD02' => 7600, 'AI03' => 7600, 'ED05' => 6800, 'IM06' => 7200];
$methods = ['网上银行', '手机银行', '银行代扣', '线下缴费'];
$currentOperators = ['苏婉宁', '王珂'];
$tuitionIndex = 0;
foreach ($studentGroups as $classCode => $group) {
    $majorCode = $classMajorMap[$classCode];
    $amount = $tuitionAmounts[$majorCode];
    foreach ($group as $groupRow) {
        $studentId = $studentIds[$groupRow[0]];
        $tuitionRoll = crc32('tuition|' . $groupRow[0]) % 100;
        if ($tuitionRoll < 58) {
            $paid = $amount;
            $status = '已缴清';
            $paidAt = '2026-08-' . str_pad((string) (26 + ($tuitionIndex % 6)), 2, '0', STR_PAD_LEFT);
            $remark = '通过线上缴费平台完成';
        } elseif ($tuitionRoll < 78) {
            $paid = round($amount * (0.3 + ($tuitionRoll % 4) * 0.1), 2);
            $status = '部分缴纳';
            $paidAt = '2026-09-' . str_pad((string) (1 + ($tuitionIndex % 8)), 2, '0', STR_PAD_LEFT);
            $remark = '剩余金额请于 11 月 30 日前缴清';
        } elseif ($tuitionRoll < 90) {
            $paid = 0.0;
            $status = '待缴费';
            $paidAt = null;
            $remark = '缴费通知已发送至学生与家长';
        } else {
            $paid = 0.0;
            $status = '缓缴';
            $paidAt = null;
            $remark = '生源地助学贷款待放款，已办理缓缴';
        }
        $insert('tuition', [
            'receipt_no' => 'CYJF-2026-' . str_pad((string) (1001 + $tuitionIndex), 4, '0', STR_PAD_LEFT),
            'student_id' => $studentId,
            'semester' => '2026-2027-1',
            'amount' => $amount,
            'paid_amount' => $paid,
            'status' => $status,
            'paid_at' => $paidAt,
            'method' => $paid > 0 ? $methods[$tuitionIndex % count($methods)] : null,
            'operator' => $currentOperators[$tuitionIndex % 2],
            'remark' => $remark,
        ]);
        $insert('tuition', [
            'receipt_no' => 'CYJF-2026-' . str_pad((string) (201 + $tuitionIndex), 4, '0', STR_PAD_LEFT),
            'student_id' => $studentId,
            'semester' => '2025-2026-2',
            'amount' => $amount,
            'paid_amount' => $amount,
            'status' => '已缴清',
            'paid_at' => '2026-03-' . str_pad((string) (1 + ($tuitionIndex % 9)), 2, '0', STR_PAD_LEFT),
            'method' => $methods[($tuitionIndex + 1) % count($methods)],
            'operator' => $currentOperators[($tuitionIndex + 1) % 2],
            'remark' => '春季学期学费',
        ]);
        $tuitionIndex++;
    }
}

$announcements = [
    ['title' => '关于2026-2027学年第一学期期中教学检查安排的通知', 'category' => '教务通知', 'content' => '本学期期中教学检查定于11月11日至11月20日开展，请各教学单位按要求完成课堂教学、实训教学与教学资料自查，并于11月18日前提交检查小结。', 'author' => '教务处', 'published_at' => '2026-11-10 08:40:00', 'pinned' => 1],
    ['title' => '2026年秋季校园运动会报名启动', 'category' => '校园活动', 'content' => '秋季校园运动会将于11月20日至21日在滨江校区田径场举行，各班级请于11月13日前完成运动员报名与入场式人员统计。', 'author' => '学生工作处', 'published_at' => '2026-11-09 14:10:00', 'pinned' => 1],
    ['title' => '本学期学费缴纳进度通报（截至11月6日）', 'category' => '财务通知', 'content' => '2026-2027学年第一学期学费收缴率已达 76.6%，请各班级辅导员提醒尚未缴费的学生于11月30日前完成缴费，缓缴学生按流程提交材料。', 'author' => '财务处', 'published_at' => '2026-11-07 16:25:00', 'pinned' => 0],
    ['title' => '明德楼多媒体教室设备检修安排', 'category' => '后勤保障', 'content' => '11月12日 18:00 至 21:00 将对明德楼 A201、A205、A301 多媒体教室进行投影与音响设备检修，相关课程如需调课请联系教务处。', 'author' => '后勤服务中心', 'published_at' => '2026-11-05 11:00:00', 'pinned' => 0],
    ['title' => '关于开展2026级新生学籍信息复核的通知', 'category' => '学籍管理', 'content' => '请2026级各班于11月15日前完成新生学籍信息核对，重点核验姓名、身份证号、出生日期与录取专业，发现异常及时报教务处学籍科。', 'author' => '教务处', 'published_at' => '2026-11-03 09:30:00', 'pinned' => 0],
    ['title' => '第十二周课程调整与补课安排', 'category' => '教务通知', 'content' => '因秋季运动会及期中教学检查安排，第十二周部分课程调整至周六补课，具体课表请各班学习委员在教务系统内查询。', 'author' => '教务处', 'published_at' => '2026-10-30 15:45:00', 'pinned' => 0],
];
foreach ($announcements as $row) {
    $insert('announcements', $row);
}

$pdo->commit();

$reportLines = ["院系,专业,班级数,在籍学生,在读学生"];
$reportRows = $pdo->query("
    SELECT d.name AS dept_name, m.name AS major_name, COUNT(DISTINCT c.id) AS class_count,
           COUNT(s.id) AS total_count, SUM(CASE WHEN s.status = '在读' THEN 1 ELSE 0 END) AS active_count
    FROM departments d
    JOIN majors m ON m.department_id = d.id
    LEFT JOIN classes c ON c.major_id = m.id
    LEFT JOIN students s ON s.class_id = c.id
    GROUP BY m.id
    ORDER BY d.code, m.code
")->fetchAll();
foreach ($reportRows as $row) {
    $reportLines[] = $row['dept_name'] . ',' . $row['major_name'] . ',' . (int) $row['class_count'] . ',' . (int) $row['total_count'] . ',' . (int) $row['active_count'];
}
file_put_contents($storagePath . '/reports/2026-11-enrollment.csv', implode("\n", $reportLines) . "\n");

$reportLines = ["班级,应到人次,实到人次,出勤率"];
$reportRows = $pdo->query("
    SELECT c.name, COUNT(*) AS total, SUM(CASE WHEN a.status = '出勤' THEN 1 ELSE 0 END) AS present
    FROM attendance a
    JOIN students s ON s.id = a.student_id
    JOIN classes c ON c.id = s.class_id
    GROUP BY c.id
    ORDER BY c.class_code
")->fetchAll();
foreach ($reportRows as $row) {
    $reportLines[] = $row['name'] . ',' . (int) $row['total'] . ',' . (int) $row['present'] . ',' . number_format((int) $row['present'] / max(1, (int) $row['total']) * 100, 1) . '%';
}
file_put_contents($storagePath . '/reports/2026-11-attendance.csv', implode("\n", $reportLines) . "\n");

$reportLines = ["学期,应收金额,实收金额,收缴率"];
$reportRows = $pdo->query("
    SELECT semester, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(paid_amount), 0) AS paid
    FROM tuition GROUP BY semester ORDER BY semester
")->fetchAll();
foreach ($reportRows as $row) {
    $reportLines[] = $row['semester'] . ',' . number_format((float) $row['amount'], 2, '.', '') . ',' . number_format((float) $row['paid'], 2, '.', '') . ',' . number_format((float) $row['paid'] / max(1, (float) $row['amount']) * 100, 1) . '%';
}
file_put_contents($storagePath . '/reports/2026-11-tuition.csv', implode("\n", $reportLines) . "\n");

if ($isDirectCli) {
    echo "数据库初始化完成：{$dbPath}\n";
    echo "学生 30 名，班级 6 个，课程 10 门，教师 12 名\n";
    echo "默认账号：admin / Chengyue@2026，registrar / Campus@2026\n";
}
