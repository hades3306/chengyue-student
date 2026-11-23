CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    real_name TEXT NOT NULL,
    role TEXT NOT NULL,
    dept TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT '启用',
    last_login_at TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE departments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    dean TEXT NOT NULL,
    phone TEXT,
    office TEXT
);

CREATE TABLE majors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    department_id INTEGER NOT NULL,
    degree TEXT NOT NULL,
    duration_years INTEGER NOT NULL DEFAULT 4,
    status TEXT NOT NULL DEFAULT '招生中',
    FOREIGN KEY (department_id) REFERENCES departments(id)
);

CREATE TABLE teachers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    teacher_no TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    gender TEXT NOT NULL,
    title TEXT NOT NULL,
    department_id INTEGER NOT NULL,
    phone TEXT,
    email TEXT,
    specialty TEXT,
    join_date TEXT,
    status TEXT NOT NULL DEFAULT '在职',
    FOREIGN KEY (department_id) REFERENCES departments(id)
);

CREATE TABLE classes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    class_code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    major_id INTEGER NOT NULL,
    grade_year INTEGER NOT NULL,
    counselor_id INTEGER,
    classroom TEXT,
    capacity INTEGER NOT NULL DEFAULT 45,
    status TEXT NOT NULL DEFAULT '在读',
    FOREIGN KEY (major_id) REFERENCES majors(id),
    FOREIGN KEY (counselor_id) REFERENCES teachers(id)
);

CREATE TABLE students (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_no TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    gender TEXT NOT NULL,
    birth_date TEXT,
    id_card TEXT,
    class_id INTEGER,
    major_id INTEGER,
    enrollment_date TEXT,
    phone TEXT,
    email TEXT,
    address TEXT,
    guardian_name TEXT,
    guardian_phone TEXT,
    status TEXT NOT NULL DEFAULT '在读',
    political_status TEXT,
    avatar TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (major_id) REFERENCES majors(id)
);

CREATE TABLE courses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    course_code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    department_id INTEGER NOT NULL,
    teacher_id INTEGER,
    credit REAL NOT NULL,
    hours INTEGER NOT NULL,
    course_type TEXT NOT NULL,
    semester TEXT NOT NULL,
    schedule TEXT,
    classroom TEXT,
    status TEXT NOT NULL DEFAULT '开课中',
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (teacher_id) REFERENCES teachers(id)
);

CREATE TABLE grades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    course_id INTEGER NOT NULL,
    semester TEXT NOT NULL,
    score REAL NOT NULL,
    exam_type TEXT NOT NULL DEFAULT '期中',
    exam_date TEXT,
    recorder TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (course_id) REFERENCES courses(id)
);

CREATE TABLE attendance (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    course_id INTEGER NOT NULL,
    class_date TEXT NOT NULL,
    period TEXT NOT NULL,
    status TEXT NOT NULL,
    remark TEXT,
    recorder TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (course_id) REFERENCES courses(id)
);

CREATE TABLE tuition (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    receipt_no TEXT NOT NULL UNIQUE,
    student_id INTEGER NOT NULL,
    semester TEXT NOT NULL,
    amount REAL NOT NULL,
    paid_amount REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    paid_at TEXT,
    method TEXT,
    operator TEXT,
    remark TEXT,
    FOREIGN KEY (student_id) REFERENCES students(id)
);

CREATE TABLE announcements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    category TEXT NOT NULL,
    content TEXT NOT NULL,
    author TEXT NOT NULL,
    published_at TEXT NOT NULL,
    pinned INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_students_class ON students(class_id);
CREATE INDEX idx_students_status ON students(status);
CREATE INDEX idx_grades_student ON grades(student_id);
CREATE INDEX idx_grades_course ON grades(course_id);
CREATE INDEX idx_attendance_date ON attendance(class_date);
CREATE INDEX idx_attendance_student ON attendance(student_id);
CREATE INDEX idx_tuition_student ON tuition(student_id);
CREATE INDEX idx_tuition_semester ON tuition(semester);
