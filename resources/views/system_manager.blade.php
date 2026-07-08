import React, { useState } from "react";
import logo from "./assets/logo.jpg";

/* ================= TYPES ================= */
type Role = "admin" | "doctor" | "student";
interface User { id: number; name: string; role: Role; }
interface Subject { name: string; doctor: string; }
interface Department { name: string; faculty: string; }
interface Quiz { title: string; degree: number; }
interface Grade { student: string; grade: number; }

/* ================= CARD COMPONENT ================= */
const Card = ({ title, value }: { title: string; value: string | number }) => (
  <div style={{
    background: "#fff",
    padding: 12,
    borderRadius: 6,
    boxShadow: "0 2px 6px rgba(0,0,0,0.1)",
    textAlign: "center" as const,
    fontSize: 14
  }}>
    <h4 style={{ margin: "6px 0" }}>{title}</h4>
    <p style={{ margin: 0 }}>{value}</p>
  </div>
);

/* ================= BUTTON STYLE ================= */
const btnStyle = {
  backgroundColor: "#2E8B86",
  color: "#fff",
  padding: "4px 8px",
  border: "none",
  cursor: "pointer"
};

/* ================= MODAL COMPONENT ================= */
interface ModalProps {
  visible: boolean;
  onClose: () => void;
  onSubmit: () => void;
  title: string;
  children: React.ReactNode;
}
const Modal = ({ visible, onClose, onSubmit, title, children }: ModalProps) => {
  if (!visible) return null;

  // زر مودال صغير جداً
  const modalBtnStyle = { 
    backgroundColor: "#2E8B86",
    color: "#fff",
    padding: "3px 6px",  // أقل حجم
    fontSize: 12,
    border: "none",
    cursor: "pointer",
    borderRadius: 4,      // تصغير الانحناء
    minWidth: 60           // حجم عرض ثابت
  };

  return (
    <div style={{
      position: "fixed",
      top: 0, left: 0, right: 0, bottom: 0,
      background: "rgba(0,0,0,0.4)",
      display: "flex",
      justifyContent: "center",
      alignItems: "center",
      zIndex: 1000
    }}>
      <div style={{
        background: "#fff",
        padding: 20,
        borderRadius: 8,
        minWidth: 300,
        maxWidth: 400
      }}>
        <h3>{title}</h3>
        <div>{children}</div>
        <div style={{ marginTop: 20, textAlign: "right" }}>
          <button style={{ ...modalBtnStyle, marginRight: 10 }} onClick={onClose}>إلغاء</button>
          <button style={modalBtnStyle} onClick={onSubmit}>حفظ</button>
        </div>
      </div>
    </div>
  );
};

/* ================= SIDEBAR ================= */
const Sidebar = ({ setPage }: { setPage: (p: string) => void }) => {
  const linkStyle = { color: "#fff", display: "block", marginBottom: 8, cursor: "pointer" };
  return (
    <div style={{ width: 220, background: "#2E8B86", color: "#fff", height: "100vh", padding: 10 }}>
      <div style={{ textAlign: "center", marginBottom: 20 }}>
        <img src={logo} alt="Logo" style={{ width: 80, height: 80, borderRadius: "50%", objectFit: "cover", boxShadow: "0 4px 10px rgba(0,0,0,0.3)", marginBottom: 10 }} />
        <h3 style={{ margin: 0 }}>مدير النظام</h3>
      </div>
      <ul style={{ listStyle: "none", padding: 0 }}>
        <li style={linkStyle} onClick={() => setPage("dashboard")}>الرئيسية</li>
        <li style={linkStyle} onClick={() => setPage("users")}>المستخدمين</li>
        <li style={linkStyle} onClick={() => setPage("subjects")}>المواد</li>
        <li style={linkStyle} onClick={() => setPage("faculties")}>الكليات</li>
        <li style={linkStyle} onClick={() => setPage("departments")}>الأقسام</li>
        <li style={linkStyle} onClick={() => setPage("quizzes")}>الكويزات</li>
        <li style={linkStyle} onClick={() => setPage("final-grades")}>الدرجات النهائية</li>
        <li style={linkStyle} onClick={() => setPage("announcements")}>الإعلانات</li>
        <li style={linkStyle} onClick={() => setPage("notifications")}>الإشعارات</li>
      </ul>
    </div>
  );
};

/* ================= COMPONENTS ================= */
const Dashboard = () => (
  <div>
    <h2>لوحة الإحصائيات</h2>
    <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(140px,1fr))", gap: 10, marginTop: 15 }}>
      <Card title="إجمالي المستخدمين" value={10} />
      <Card title="عدد الطلاب" value={6} />
      <Card title="عدد الدكاترة" value={3} />
      <Card title="عدد المواد" value={5} />
      <Card title="عدد الكويزات" value={8} />
      <Card title="عدد التمارين" value={12} />
      <Card title="نسبة الحضور" value={"85%"} />
    </div>
  </div>
);

const Users = () => {
  const [users, setUsers] = useState<User[]>([{ id: 1, name: "Ali", role: "student" }]);
  const [modalVisible, setModalVisible] = useState(false);
  const [name, setName] = useState("");
  const [role, setRole] = useState<Role>("student");

  const addUser = () => {
    if (!name.trim()) { alert("أدخل الاسم"); return; }
    setUsers([...users, { id: Date.now(), name, role }]);
    setName(""); setRole("student"); setModalVisible(false);
  };

  return (
    <div>
      <h2>المستخدمين</h2>
      <button style={btnStyle} onClick={() => setModalVisible(true)}>إضافة مستخدم</button>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(140px,1fr))", gap: 10, marginTop: 15 }}>
        {users.map(u => <Card key={u.id} title={u.name} value={u.role} />)}
      </div>
      <Modal visible={modalVisible} onClose={() => setModalVisible(false)} onSubmit={addUser} title="إضافة مستخدم جديد">
        <input type="text" placeholder="الاسم" value={name} onChange={e => setName(e.target.value)} style={{ width: "100%", padding: 6, marginBottom: 10, borderRadius: 4, border: "1px solid #ccc" }} />
        <select value={role} onChange={e => setRole(e.target.value as Role)} style={{ width: "100%", padding: 6, borderRadius: 4, border: "1px solid #ccc" }}>
          <option value="student">طالب</option>
          <option value="doctor">دكتور</option>
          <option value="admin">أدمن</option>
        </select>
      </Modal>
    </div>
  );
};

/* ================= ANNOUNCEMENTS ================= */
const Announcements = () => {
  const [announcements, setAnnouncements] = useState([]);
  const [colleges, setColleges] = useState([]);
  const [modalVisible, setModalVisible] = useState(false);
  const [editItem, setEditItem] = useState(null);
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [targetRole, setTargetRole] = useState("college_manager");
  const [targetCollegeId, setTargetCollegeId] = useState("");

  const API = "/api/admin/announcements";

  const loadAnnouncements = async () => {
    try {
      const r = await fetch(`${API}?scope=system`);
      const d = await r.json();
      if (d.success) setAnnouncements(d.data);
    } catch (e) { console.error(e); }
  };

  const loadColleges = async () => {
    try {
      const r = await fetch("/api/admin/colleges-for-select");
      const d = await r.json();
      if (d.success) setColleges(d.data);
    } catch (e) { console.error(e); }
  };

  React.useEffect(() => { loadAnnouncements(); loadColleges(); }, []);

  const openCreate = () => {
    setEditItem(null);
    setTitle(""); setBody(""); setTargetRole("college_manager"); setTargetCollegeId("");
    setModalVisible(true);
  };

  const openEdit = (a) => {
    setEditItem(a);
    setTitle(a.title); setBody(a.body); setTargetRole(a.target_role); setTargetCollegeId(a.target_college_id || "");
    setModalVisible(true);
  };

  const submit = async () => {
    if (!title.trim() || !body.trim()) { alert("العنوان والنص مطلوبان"); return; }
    const payload = {
      sender_id: 1,
      sender_role: "system_admin",
      title, body,
      target_role: targetRole,
      target_college_id: targetCollegeId || null,
    };
    try {
      const url = editItem ? `${API}/${editItem.id}` : API;
      const method = editItem ? "PUT" : "POST";
      const r = await fetch(url, { method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
      const d = await r.json();
      if (d.success) {
        alert(d.message);
        setModalVisible(false);
        loadAnnouncements();
      } else { alert(d.message); }
    } catch (e) { alert("خطأ في الاتصال"); }
  };

  const deleteAnn = async (id, title) => {
    if (!confirm(`هل أنت متأكد من حذف الإعلان "${title}"؟`)) return;
    try {
      const r = await fetch(`${API}/${id}`, { method: "DELETE" });
      const d = await r.json();
      if (d.success) { alert(d.message); loadAnnouncements(); }
      else { alert(d.message); }
    } catch (e) { alert("خطأ في الاتصال"); }
  };

  const roleLabels = {
    college_manager: "مديري الكليات",
    doctor: "الدكاترة",
    student: "الطلاب",
    all: "الجميع",
  };

  return (
    <div>
      <h2>إعلانات النظام</h2>
      <button style={btnStyle} onClick={openCreate}>إرسال إعلان جديد</button>
      <table style={{ width: "100%", borderCollapse: "collapse", marginTop: 15, fontSize: 13 }}>
        <thead>
          <tr style={{ background: "#2E8B86", color: "#fff" }}>
            <th style={thStyle}>العنوان</th>
            <th style={thStyle}>النص</th>
            <th style={thStyle}>المستهدف</th>
            <th style={thStyle}>الكلية</th>
            <th style={thStyle}>المرسل</th>
            <th style={thStyle}>التاريخ</th>
            <th style={thStyle}>إجراءات</th>
          </tr>
        </thead>
        <tbody>
          {announcements.map(a => (
            <tr key={a.id} style={{ borderBottom: "1px solid #ddd" }}>
              <td style={tdStyle}>{a.title}</td>
              <td style={tdStyle}>{a.body?.substring(0, 60)}{a.body?.length > 60 ? "..." : ""}</td>
              <td style={tdStyle}>{roleLabels[a.target_role] || a.target_role}</td>
              <td style={tdStyle}>{a.target_college_name || "الكل"}</td>
              <td style={tdStyle}>{a.sender_name}</td>
              <td style={tdStyle}>{new Date(a.created_at).toLocaleDateString("ar-SA")}</td>
              <td style={tdStyle}>
                <button style={{ ...btnStyle, marginLeft: 5 }} onClick={() => openEdit(a)}>تعديل</button>
                <button style={{ ...btnStyle, backgroundColor: "#c0392b" }} onClick={() => deleteAnn(a.id, a.title)}>حذف</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <Modal visible={modalVisible} onClose={() => setModalVisible(false)} onSubmit={submit} title={editItem ? "تعديل الإعلان" : "إرسال إعلان جديد"}>
        <input type="text" placeholder="عنوان الإعلان" value={title} onChange={e => setTitle(e.target.value)} style={{ width: "100%", padding: 6, marginBottom: 10, borderRadius: 4, border: "1px solid #ccc", boxSizing: "border-box" }} />
        <textarea placeholder="نص الإعلان" value={body} onChange={e => setBody(e.target.value)} rows={4} style={{ width: "100%", padding: 6, marginBottom: 10, borderRadius: 4, border: "1px solid #ccc", boxSizing: "border-box" }} />
        <select value={targetRole} onChange={e => setTargetRole(e.target.value)} style={{ width: "100%", padding: 6, marginBottom: 10, borderRadius: 4, border: "1px solid #ccc" }}>
          <option value="college_manager">مديري الكليات</option>
          <option value="doctor">الدكاترة</option>
          <option value="student">الطلاب</option>
          <option value="all">الجميع</option>
        </select>
        <select value={targetCollegeId} onChange={e => setTargetCollegeId(e.target.value)} style={{ width: "100%", padding: 6, marginBottom: 10, borderRadius: 4, border: "1px solid #ccc" }}>
          <option value="">جميع الكليات</option>
          {colleges.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </Modal>
    </div>
  );
};

const thStyle = { padding: "8px 10px", textAlign: "right" as const, fontWeight: 600 };
const tdStyle = { padding: "8px 10px", textAlign: "right" as const, maxWidth: 200, overflow: "hidden", textOverflow: "ellipsis" };

/* ================= NOTIFICATIONS (إرسال إشعار) ================= */
const Notifications = () => {
  const [modalVisible, setModalVisible] = useState(false);
  const [colleges, setColleges] = useState([]);
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [targetRole, setTargetRole] = useState("college_manager");
  const [targetCollegeId, setTargetCollegeId] = useState("");

  React.useEffect(() => {
    fetch("/api/admin/colleges-for-select").then(r => r.json()).then(d => { if (d.success) setColleges(d.data); }).catch(() => {});
  }, []);

  const sendNotif = async () => {
    if (!title.trim() || !body.trim()) { alert("العنوان والنص مطلوبان"); return; }
    try {
      const r = await fetch("/api/admin/send-notification", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          sender_id: 1,
          title, body,
          target_role: targetRole,
          target_college_id: targetCollegeId || null,
        }),
      });
      const d = await r.json();
      if (d.success) { alert(d.message); setModalVisible(false); setTitle(""); setBody(""); }
      else { alert(d.message); }
    } catch (e) { alert("خطأ في الاتصال"); }
  };

  return (
    <div>
      <h2>إرسال إشعار</h2>
      <button style={btnStyle} onClick={() => setModalVisible(true)}>إرسال إشعار جديد</button>
      <Modal visible={modalVisible} onClose={() => setModalVisible(false)} onSubmit={sendNotif} title="إرسال إشعار">
        <input type="text" placeholder="عنوان الإشعار" value={title} onChange={e => setTitle(e.target.value)} style={{ width: "100%", padding: 6, marginBottom: 10, borderRadius: 4, border: "1px solid #ccc", boxSizing: "border-box" }} />
        <textarea placeholder="نص الإشعار" value={body} onChange={e => setBody(e.target.value)} rows={4} style={{ width: "100%", padding: 6, marginBottom: 10, borderRadius: 4, border: "1px solid #ccc", boxSizing: "border-box" }} />
        <select value={targetRole} onChange={e => setTargetRole(e.target.value)} style={{ width: "100%", padding: 6, marginBottom: 10, borderRadius: 4, border: "1px solid #ccc" }}>
          <option value="college_manager">مديري الكليات</option>
          <option value="doctor">الدكاترة</option>
          <option value="student">الطلاب</option>
          <option value="all">الجميع</option>
        </select>
        <select value={targetCollegeId} onChange={e => setTargetCollegeId(e.target.value)} style={{ width: "100%", padding: 6, marginBottom: 10, borderRadius: 4, border: "1px solid #ccc" }}>
          <option value="">جميع الكليات</option>
          {colleges.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </Modal>
    </div>
  );
};

/* ================= MAIN APP ================= */
const App = () => {
  const [page, setPage] = useState("dashboard");

  return (
    <div style={{ display: "flex" }}>
      <Sidebar setPage={setPage} />
      <div style={{ flex: 1, padding: 20 }}>
        {page === "dashboard" && <Dashboard />}
        {page === "users" && <Users />}
        {page === "announcements" && <Announcements />}
        {page === "notifications" && <Notifications />}
        {/* ضع هنا باقي المكونات بنفس النمط */}
      </div>
    </div>
  );
};

export default App;