import React, { useState } from "react";
import { BrowserRouter, Routes, Route, Link } from "react-router-dom";
import logo from "./assets/logo.jpg";

/* ================= TYPES ================= */
type Role = "admin" | "doctor" | "student";

interface User {
  id: number;
  name: string;
  role: Role;
}

/* ================= STYLES ================= */
const btnStyle = {
  backgroundColor: "#2E8B86",
  color: "#fff",
  padding: "4px 8px",
  border: "none",
  marginBottom: 10,
  cursor: "pointer"
};

/* ================= SIDEBAR ================= */
const Sidebar = () => {
  const sidebarStyle = {
    width: 220,
    background: "#2E8B86",
    color: "#fff",
    height: "100vh",
    padding: 10,
  };

  const linkStyle = {
    color: "#fff",
    display: "block",
    marginBottom: 8,
    textDecoration: "none"
  };

  return (
    <div style={sidebarStyle}>
      <div style={{ textAlign: "center", marginBottom: 20 }}>
        <img
          src={logo}
          alt="Logo"
          style={{
            width: 100,
            height: 100,
            borderRadius: "50%",
            objectFit: "cover",
            boxShadow: "0 4px 10px rgba(0,0,0,0.3)",
            marginBottom: 10,
          }}
        />
        <h3 style={{ margin: 0 }}>مدير النظام</h3>
      </div>
      <ul style={{ listStyle: "none", padding: 0 }}>
        <li><Link to="/" style={linkStyle}>الرئيسية</Link></li>
        <li><Link to="/users" style={linkStyle}>المستخدمين</Link></li>
        <li><Link to="/subjects" style={linkStyle}>المواد</Link></li>
        <li><Link to="/faculties" style={linkStyle}>الكليات</Link></li>
        <li><Link to="/departments" style={linkStyle}>الأقسام</Link></li>
        <li><Link to="/quizzes" style={linkStyle}>الكويزات</Link></li>
        <li><Link to="/final-grades" style={linkStyle}>الدرجات النهائية</Link></li>
        <li><Link to="/notifications" style={linkStyle}>الإشعارات</Link></li>
      </ul>
    </div>
  );
};

/* ================= CARD COMPONENT ================= */
const Card = ({ title, value }: { title: string; value: string | number }) => {
  return (
    <div style={{
      background: "#fff",
      padding: 12,
      borderRadius: 8,
      boxShadow: "0 2px 6px rgba(0,0,0,0.1)",
      textAlign: "center" as const,
      fontSize: 14,
      minWidth: 140,
    }}>
      <h4 style={{ margin: "5px 0" }}>{value}</h4>
      <p style={{ margin: 0, fontSize: 12, color: "#555" }}>{title}</p>
    </div>
  );
};

/* ================= MODAL COMPONENT ================= */
const Modal = ({ visible, onClose, onSubmit, title, children }: 
  { visible: boolean; onClose: () => void; onSubmit: () => void; title: string; children: React.ReactNode }) => {
  if (!visible) return null;

  return (
    <div style={{
      position: "fixed",
      top: 0, left: 0, right: 0, bottom: 0,
      backgroundColor: "rgba(0,0,0,0.4)",
      display: "flex",
      justifyContent: "center",
      alignItems: "center",
      zIndex: 999
    }}>
      <div style={{
        background: "#fff",
        padding: 20,
        borderRadius: 8,
        width: 280,
        boxShadow: "0 2px 10px rgba(0,0,0,0.2)",
        position: "relative"
      }}>
        <h3 style={{ marginTop: 0 }}>{title}</h3>
        {children}
        <div style={{ marginTop: 15, textAlign: "right" }}>
          <button style={{ marginRight: 10 }} onClick={onClose}>إلغاء</button>
          <button onClick={onSubmit}>حفظ</button>
        </div>
      </div>
    </div>
  );
};

/* ================= GENERIC GRID LAYOUT ================= */
const GridLayout = ({ children }: { children: React.ReactNode }) => (
  <div style={{
    display: "grid",
    gridTemplateColumns: "repeat(auto-fill, minmax(140px, 1fr))",
    gap: 10,
    marginTop: 15
  }}>
    {children}
  </div>
);

/* ================= USERS ================= */
const Users = () => {
  const [users, setUsers] = useState<User[]>([
    { id: 1, name: "Ali", role: "student" },
    { id: 2, name: "Sara", role: "doctor" },
    { id: 3, name: "Omar", role: "student" },
  ]);

  const [modalVisible, setModalVisible] = useState(false);
  const [newName, setNewName] = useState("");
  const [newRole, setNewRole] = useState<Role>("student");

  const addUser = () => {
    if (!newName) return alert("الاسم مطلوب");
    setUsers([...users, { id: Date.now(), name: newName, role: newRole }]);
    setModalVisible(false);
    setNewName("");
    setNewRole("student");
  };

  return (
    <div>
      <h2>إدارة المستخدمين</h2>
      <button style={btnStyle} onClick={() => setModalVisible(true)}>إضافة مستخدم</button>
      <GridLayout>
        {users.map(u => <Card key={u.id} title="الدور" value={`${u.name} (${u.role})`} />)}
      </GridLayout>

      <Modal visible={modalVisible} onClose={() => setModalVisible(false)} onSubmit={addUser} title="إضافة مستخدم جديد">
        <input placeholder="الاسم" value={newName} onChange={e => setNewName(e.target.value)} />
        <select value={newRole} onChange={e => setNewRole(e.target.value as Role)}>
          <option value="student">طالب</option>
          <option value="doctor">دكتور</option>
          <option value="admin">أدمن</option>
        </select>
      </Modal>
    </div>
  );
};

/* ================= SUBJECTS ================= */
const Subjects = () => {
  const [subjects, setSubjects] = useState([
    "برمجة 1", "قواعد بيانات", "خوارزميات", "شبكات", "ذكاء اصطناعي", "هندسة برمجيات", "نظم تشغيل", "واجهات مستخدم"
  ]);

  const [modalVisible, setModalVisible] = useState(false);
  const [newSubject, setNewSubject] = useState("");

  const addSubject = () => {
    if (!newSubject) return alert("اسم المادة مطلوب");
    setSubjects([...subjects, newSubject]);
    setModalVisible(false);
    setNewSubject("");
  };

  return (
    <div>
      <h2>إدارة المواد</h2>
      <button style={btnStyle} onClick={() => setModalVisible(true)}>إضافة مادة</button>
      <GridLayout>
        {subjects.map((s, i) => <Card key={i} title="مادة" value={s} />)}
      </GridLayout>

      <Modal visible={modalVisible} onClose={() => setModalVisible(false)} onSubmit={addSubject} title="إضافة مادة جديدة">
        <input placeholder="اسم المادة" value={newSubject} onChange={e => setNewSubject(e.target.value)} />
      </Modal>
    </div>
  );
};

/* ================= FACULTIES ================= */
const Faculties = () => {
  const [faculties, setFaculties] = useState(["الهندسة", "الطب", "الصيدلة", "علوم الحاسوب"]);

  const [modalVisible, setModalVisible] = useState(false);
  const [newFaculty, setNewFaculty] = useState("");

  const addFaculty = () => {
    if (!newFaculty) return alert("اسم الكلية مطلوب");
    setFaculties([...faculties, newFaculty]);
    setModalVisible(false);
    setNewFaculty("");
  };

  return (
    <div>
      <h2>إدارة الكليات</h2>
      <button style={btnStyle} onClick={() => setModalVisible(true)}>إضافة كلية</button>
      <GridLayout>
        {faculties.map((f, i) => <Card key={i} title="كلية" value={f} />)}
      </GridLayout>

      <Modal visible={modalVisible} onClose={() => setModalVisible(false)} onSubmit={addFaculty} title="إضافة كلية جديدة">
        <input placeholder="اسم الكلية" value={newFaculty} onChange={e => setNewFaculty(e.target.value)} />
      </Modal>
    </div>
  );
};

/* ================= DEPARTMENTS ================= */
const Departments = () => {
  const [departments, setDepartments] = useState(["تقنية معلومات", "شبكات", "هندسة البرمجيات", "علوم البيانات"]);

  const [modalVisible, setModalVisible] = useState(false);
  const [newDept, setNewDept] = useState("");

  const addDept = () => {
    if (!newDept) return alert("اسم القسم مطلوب");
    setDepartments([...departments, newDept]);
    setModalVisible(false);
    setNewDept("");
  };

  return (
    <div>
      <h2>إدارة الأقسام</h2>
      <button style={btnStyle} onClick={() => setModalVisible(true)}>إضافة قسم</button>
      <GridLayout>
        {departments.map((d, i) => <Card key={i} title="قسم" value={d} />)}
      </GridLayout>

      <Modal visible={modalVisible} onClose={() => setModalVisible(false)} onSubmit={addDept} title="إضافة قسم جديد">
        <input placeholder="اسم القسم" value={newDept} onChange={e => setNewDept(e.target.value)} />
      </Modal>
    </div>
  );
};

/* ================= DASHBOARD ================= */
const Dashboard = () => {
  const users: User[] = [
    { id: 1, name: "Ali", role: "student" },
    { id: 2, name: "Sara", role: "doctor" },
    { id: 3, name: "Omar", role: "student" },
  ];
  const subjects = 8;
  const quizzes = 5;
  const assignments = 10;
  const totalAttendance = 100;
  const presentCount = 85;

  const totalUsers = users.length;
  const students = users.filter(u => u.role === "student").length;
  const doctors = users.filter(u => u.role === "doctor").length;
  const attendanceRate = ((presentCount / totalAttendance) * 100).toFixed(1);

  return (
    <div>
      <h2 style={{ marginBottom: 15 }}>لوحة الإحصائيات</h2>
      <GridLayout>
        <Card title="إجمالي المستخدمين" value={totalUsers} />
        <Card title="عدد الطلاب" value={students} />
        <Card title="عدد الدكاترة" value={doctors} />
        <Card title="عدد المواد" value={subjects} />
        <Card title="عدد الكويزات" value={quizzes} />
        <Card title="عدد التكاليف" value={assignments} />
        <Card title="نسبة الحضور" value={`${attendanceRate}%`} />
      </GridLayout>
    </div>
  );
};

/* ================= LAYOUT ================= */
const Layout = ({ children }: { children: React.ReactNode }) => (
  <div style={{ display: "flex" }}>
    <Sidebar />
    <div style={{ padding: 20, width: "100%" }}>{children}</div>
  </div>
);

/* ================= APP ================= */
export default function App() {
  return (
    <BrowserRouter>
      <Layout>
        <Routes>
          <Route path="/" element={<Dashboard />} />
          <Route path="/users" element={<Users />} />
          <Route path="/subjects" element={<Subjects />} />
          <Route path="/faculties" element={<Faculties />} />
          <Route path="/departments" element={<Departments />} />
        </Routes>
      </Layout>
    </BrowserRouter>
  );
}