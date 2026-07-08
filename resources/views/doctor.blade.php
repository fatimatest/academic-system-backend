import React, { useState, useEffect } from "react";
import { BrowserRouter as Router, Routes, Route, Link, useLocation } from "react-router-dom";
import logo from "./assets/logo.jpg";
import images from "./assets/images.png"; // ضع اسم الصورة هنا

const primary = "#2E8B86";

/* ================= API Helpers ================= */
const API_BASE = 'http://192.168.0.3:8000/api';
const DOCTOR_ID = 10011;

async function apiPost(path, data) {
  const res = await fetch(`${API_BASE}/${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  });
  return res.json();
}

async function apiGet(path) {
  const res = await fetch(`${API_BASE}/${path}`);
  return res.json();
}

async function apiPostFormData(path, formData) {
  const res = await fetch(`${API_BASE}/${path}`, {
    method: 'POST',
    body: formData,
  });
  return res.json();
}

async function apiDelete(path) {
  const res = await fetch(`${API_BASE}/${path}`, {
    method: 'DELETE',
  });
  return res.json();
}

/* ================= Sidebar ================= */
const DoctorSidebar = () => {
  const location = useLocation();
  const menu = [
    { name: "Dashboard", path: "/" },
    { name: "المواد", path: "/subjects" },
    { name: "الحضور", path: "/attendance" },
    { name: "الكويزات", path: "/quizzes" },
    { name: "التكاليف", path: "/assignments" },
    { name: "الدرجات النهائية", path: "/final-grades" },
    { name: "الإشعارات", path: "/notifications" },
  ];

  return (
    <div
      style={{
        width: "250px",
        background: primary,
        color: "#fff",
        minHeight: "100vh",
        padding: "25px 20px",
        order: 2, // 👈 هذا يجعلها على اليمين داخل flex
      }}
    >
      <div style={{ textAlign: "center", marginBottom: "40px" }}>
        <img
          src={logo}
          alt="Logo"
          style={{
            width: "120px",
            height: "120px",
            borderRadius: "50%",
            objectFit: "cover",
            boxShadow: "0 4px 10px rgba(0,0,0,0.3)",
            marginBottom: "10px",
          }}
        />
      </div>
      <ul style={{ listStyle: "none", padding: 0 }}>
        {menu.map((item) => {
          const active = location.pathname === item.path;
          return (
            <li key={item.path} style={{ marginBottom: "15px" }}>
              <Link
                to={item.path}
                style={{
                  display: "block",
                  padding: "12px 15px",
                  borderRadius: "8px",
                  textDecoration: "none",
                  color: "#fff",
                  background: active ? "rgba(255,255,255,0.2)" : "transparent",
                }}
              >
                {item.name}
              </Link>
            </li>
          );
        })}
      </ul>
    </div>
  );
};

/* ================= Top Navbar ================= */
const TopNavbar = () => (
  <div
    style={{
      background: "#fff",
      padding: "15px 25px",
      display: "flex",
      justifyContent: "space-between",
      alignItems: "center",
      boxShadow: "0 2px 10px rgba(0,0,0,0.05)",
    }}
  >
    <h2 style={{ margin: 0 }}>لوحة الدكتور</h2>
    <div style={{ display: "flex", alignItems: "center", gap: "20px" }}>
      <div style={{ position: "relative", cursor: "pointer" }}>
        <span style={{ fontSize: "22px" }}>🔔</span>
        <span
          style={{
            position: "absolute",
            top: "-5px",
            left: "-8px",
            background: "red",
            color: "#fff",
            fontSize: "12px",
            padding: "2px 6px",
            borderRadius: "50%",
          }}
        >
          3
        </span>
      </div>
      <div
        style={{
          width: "40px",
          height: "40px",
          borderRadius: "50%",
          background: primary,
          color: "#fff",
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          fontWeight: "bold",
        }}
      >
        JD
      </div>
    </div>
  </div>
);

/* ================= Stat Card ================= */
const StatCard = ({ title, value }: { title: string; value: number }) => (
  <div
    style={{
      background: "#fff",
      padding: "25px",
      borderRadius: "15px",
      width: "200px",
      boxShadow: "0 5px 20px rgba(0,0,0,0.05)",
    }}
  >
    <h4 style={{ marginBottom: "10px", color: "#555" }}>{title}</h4>
    <p style={{ fontSize: "28px", fontWeight: "bold", color: primary }}>{value}</p>
  </div>
);

/* ================= Dashboard ================= */
const DoctorDashboard = () => {
  const [loading, setLoading] = useState(true);
  const [requests, setRequests] = useState([]);
  const [pending, setPending] = useState([]);
  const [processing, setProcessing] = useState(false);
  const [summary, setSummary] = useState(null);

  useEffect(() => {
    loadRequests();
  }, []);

  const loadRequests = async () => {
    setLoading(true);
    setSummary(null);
    try {
      const res = await apiGet(`join-requests/${DOCTOR_ID}?status=pending`);
      if (res.success && res.data) {
        setRequests(res.data);
        setPending(res.data);
      }
    } catch (e) {
      console.error('Failed to load requests', e);
    }
    setLoading(false);
  };

  const processSingle = async (id, action, verifyFirst = false) => {
    if (processing) return;
    setProcessing(true);
    try {
      const res = await apiPost(`join-requests/${id}/process`, {
        action,
        verify_first: verifyFirst,
        rejection_reason: action === 'reject' ? 'تم رفض الطلب' : '',
      });
      if (res.success) {
        await loadRequests();
      } else {
        alert(res.message || 'حدث خطأ');
      }
    } catch (e) {
      alert('فشل الاتصال بالخادم');
    }
    setProcessing(false);
  };

  const batchProcess = async (verifyFirst = false) => {
    if (processing || pending.length === 0) return;
    setProcessing(true);
    setSummary(null);
    try {
      const ids = pending.map(r => r.id);
      const res = await apiPost('join-requests/batch-verify', {
        request_ids: ids,
        verify_first: verifyFirst,
      });
      if (res.success) {
        setSummary(res.data);
        await loadRequests();
      } else {
        alert(res.message || 'حدث خطأ');
      }
    } catch (e) {
      alert('فشل الاتصال بالخادم');
    }
    setProcessing(false);
  };

  const stats = { subjects: 5, students: 120, quizzes: 10, assignments: 8 };

  return (
    <div style={{ padding: "30px" }}>
      <div style={{ display: "flex", gap: "20px", marginBottom: "30px", flexWrap: "wrap" }}>
        <StatCard title="المواد" value={stats.subjects} />
        <StatCard title="الطلاب" value={stats.students} />
        <StatCard title="الكويزات" value={stats.quizzes} />
        <StatCard title="التكاليف" value={stats.assignments} />
        <StatCard title="طلبات معلقة" value={pending.length} />
      </div>

      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "15px" }}>
        <h3 style={{ margin: 0 }}>طلبات تسجيل الطلاب</h3>
        {pending.length > 0 && (
          <div style={{ display: "flex", gap: "10px" }}>
            <button
              onClick={() => batchProcess(false)}
              disabled={processing}
              style={{
                background: primary + "20",
                color: primary,
                border: "1px solid " + primary,
                padding: "8px 16px",
                borderRadius: "8px",
                fontSize: "13px",
                cursor: processing ? "not-allowed" : "pointer",
                opacity: processing ? 0.6 : 1,
              }}
            >
              قبول الكل المباشر
            </button>
            <button
              onClick={() => batchProcess(true)}
              disabled={processing}
              style={{
                background: primary,
                color: "#fff",
                border: "none",
                padding: "8px 16px",
                borderRadius: "8px",
                fontSize: "13px",
                cursor: processing ? "not-allowed" : "pointer",
                opacity: processing ? 0.6 : 1,
              }}
            >
              قبول الكل موثق
            </button>
          </div>
        )}
      </div>

      {summary && (
        <div style={{
          background: "#fff",
          border: "2px solid " + primary,
          borderRadius: "12px",
          padding: "20px",
          marginBottom: "20px",
        }}>
          <h4 style={{ margin: "0 0 10px 0", color: primary }}>نتائج المعالجة</h4>
          <p style={{ margin: "5px 0", fontSize: "16px" }}>
            تم فحص <strong>{summary.total}</strong> طلباً
          </p>
          <p style={{ margin: "5px 0", color: "#27ae60", fontSize: "16px" }}>
            ✓ تم قبول <strong>{summary.accepted_count}</strong> طالباً
          </p>
          {summary.rejected_count > 0 && (
            <>
              <p style={{ margin: "5px 0", color: "#e74c3c", fontSize: "16px" }}>
                ✗ تم رفض <strong>{summary.rejected_count}</strong> طلب
              </p>
              <div style={{ marginTop: "10px", background: "#fdf2f2", borderRadius: "8px", padding: "10px" }}>
                {summary.rejected.map((r, i) => (
                  <p key={i} style={{ margin: "3px 0", fontSize: "13px", color: "#c0392b" }}>
                    {r.student_name} — {r.reason}
                  </p>
                ))}
              </div>
            </>
          )}
          <button
            onClick={() => setSummary(null)}
            style={{
              marginTop: "10px",
              background: "#eee",
              border: "none",
              padding: "6px 14px",
              borderRadius: "6px",
              cursor: "pointer",
              fontSize: "12px",
            }}
          >
            إخفاء
          </button>
        </div>
      )}

      {loading ? (
        <p style={{ textAlign: "center", color: "#888", padding: "40px" }}>جاري التحميل...</p>
      ) : (
        <table
          style={{
            width: "100%",
            borderCollapse: "collapse",
            background: "#fff",
            borderRadius: "12px",
            overflow: "hidden",
            boxShadow: "0 4px 15px rgba(0,0,0,0.05)",
          }}
        >
          <thead>
            <tr style={{ background: "#f0f3f6" }}>
              <th style={{ padding: "12px", textAlign: "right" }}>الاسم</th>
              <th style={{ padding: "12px", textAlign: "right" }}>المادة</th>
              <th style={{ padding: "12px", textAlign: "right" }}>الرقم الأكاديمي</th>
              <th style={{ padding: "12px", textAlign: "right" }}>الإجراء</th>
            </tr>
          </thead>
          <tbody>
            {pending.map((s) => (
              <tr key={s.id}>
                <td style={{ padding: "12px", borderBottom: "1px solid #eee", fontWeight: "bold" }}>{s.student_name || s.name}</td>
                <td style={{ padding: "12px", borderBottom: "1px solid #eee" }}>{s.subject_name || s.subject}</td>
                <td style={{ padding: "12px", borderBottom: "1px solid #eee", direction: "ltr", textAlign: "right" }}>{s.academic_number || '-'}</td>
                <td style={{ padding: "8px", borderBottom: "1px solid #eee" }}>
                  <div style={{ display: "flex", gap: "4px" }}>
                    <button
                      onClick={() => processSingle(s.id, 'approve', true)}
                      disabled={processing}
                      style={{
                        background: primary,
                        color: "#fff",
                        border: "none",
                        padding: "4px 10px",
                        borderRadius: "4px",
                        fontSize: "11px",
                        cursor: processing ? "not-allowed" : "pointer",
                        opacity: processing ? 0.6 : 1,
                      }}
                    >
                      قبول موثق
                    </button>
                    <button
                      onClick={() => processSingle(s.id, 'approve', false)}
                      disabled={processing}
                      style={{
                        background: "#1abc9c",
                        color: "#fff",
                        border: "none",
                        padding: "4px 10px",
                        borderRadius: "4px",
                        fontSize: "11px",
                        cursor: processing ? "not-allowed" : "pointer",
                        opacity: processing ? 0.6 : 1,
                      }}
                    >
                      قبول مباشر
                    </button>
                    <button
                      onClick={() => processSingle(s.id, 'reject')}
                      disabled={processing}
                      style={{
                        background: "#e74c3c",
                        color: "#fff",
                        border: "none",
                        padding: "4px 10px",
                        borderRadius: "4px",
                        fontSize: "11px",
                        cursor: processing ? "not-allowed" : "pointer",
                        opacity: processing ? 0.6 : 1,
                      }}
                    >
                      رفض
                    </button>
                  </div>
                </td>
              </tr>
            ))}
            {pending.length === 0 && (
              <tr>
                <td colSpan={4} style={{ padding: "20px", textAlign: "center", color: "#888" }}>
                  لا يوجد طلبات حالياً
                </td>
              </tr>
            )}
          </tbody>
        </table>
      )}
    </div>
  );
};

/* ================= صفحة فارغة ================= */
const EmptyPage = ({ title }: { title: string }) => (
  <div style={{ padding: "30px" }}>
    <h2>{title}</h2>
  </div>
);
const SubjectsPage = () => {
  const subjects = [
    { id: 1, name: "Math", code: "MTH101", term: "الأول", students: 40 },
    { id: 2, name: "Physics", code: "PHY201", term: "الثاني", students: 35 },
    { id: 3, name: "Chemistry", code: "CHM102", term: "الأول", students: 45 },
  ];

  return (
    <div style={{ padding: "30px" }}>
      <h2>المواد الخاصة بي</h2>

      <table
        style={{
          width: "100%",
          borderCollapse: "collapse",
          background: "#fff",
          textAlign: "center",
        }}
      >
        <thead>
          <tr style={{ background: "#f0f3f6" }}>
            <th style={{ border: "1px solid #ccc", padding: "10px" }}>اسم المادة</th>
            <th style={{ border: "1px solid #ccc", padding: "10px" }}>الكود</th>
            <th style={{ border: "1px solid #ccc", padding: "10px" }}>الترم</th>
            <th style={{ border: "1px solid #ccc", padding: "10px" }}>عدد الطلاب</th>
          </tr>
        </thead>

        <tbody>
          {subjects.map((s) => (
            <tr key={s.id}>
              <td style={{ border: "1px solid #ccc", padding: "10px" }}>{s.name}</td>
              <td style={{ border: "1px solid #ccc", padding: "10px" }}>{s.code}</td>
              <td style={{ border: "1px solid #ccc", padding: "10px" }}>{s.term}</td>
              <td style={{ border: "1px solid #ccc", padding: "10px" }}>{s.students}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};
const AttendancePage = () => {
  const [sessionOpen, setSessionOpen] = useState(false);
  const students = ["Ali", "Ahmed", "Sara", "Mona"];

  return (
    <div style={{ padding: "30px" }}>
      <h2>إدارة الحضور</h2>

      {!sessionOpen && (
        <button
          onClick={() => setSessionOpen(true)}
          style={{
            background: primary,
            color: "#fff",
            border: "none",
            padding: "10px 15px",
            borderRadius: "8px",
            cursor: "pointer",
          }}
        >
          فتح جلسة حضور
        </button>
      )}

      {sessionOpen && (
        <>
          <h3>QR Code للحضور</h3>

          <div
  style={{
    width: "200px",
    height: "200px",
    borderRadius: "10px",
    overflow: "hidden",
    boxShadow: "0 2px 8px rgba(0,0,0,0.1)",
  }}
>
  <img
    src={images}
    alt="QR Code"
    style={{ width: "100%", height: "100%", objectFit: "cover" }}
  />
</div>

          <h3 style={{ marginTop: "30px" }}>الطلاب الحاضرين</h3>

          <ul>
            {students.map((s, i) => (
              <li key={i}>{s}</li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
};



const QuizPage = () => {
  const [subjects, setSubjects] = useState([]);
  const [quizzes, setQuizzes] = useState([]);
  const [selectedSubject, setSelectedSubject] = useState('');
  const [offerings, setOfferings] = useState([]);
  const [selectedOfferingIds, setSelectedOfferingIds] = useState([]);
  const [selectAll, setSelectAll] = useState(false);
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [maxGrade, setMaxGrade] = useState(10);
  const [file, setFile] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    apiPost('users/courses', { user_id: DOCTOR_ID, role: 'doctor' }).then(res => {
      if (res.status === 'success' && res.courses) setSubjects(res.courses);
    });
    loadQuizzes();
  }, []);

  const loadQuizzes = () => {
    apiGet(`doctor/quizzes/${DOCTOR_ID}`).then(res => {
      if (res.success) {
        setQuizzes(res.data || []);
      }
    });
  };

  const handleSubjectChange = (e) => {
    const name = e.target.value;
    setSelectedSubject(name);
    const sub = subjects.find(s => s.subject_name === name);
    if (sub) {
      setOfferings(sub.offerings || []);
    } else {
      setOfferings([]);
    }
    setSelectedOfferingIds([]);
    setSelectAll(false);
  };

  const handleSelectAll = (e) => {
    const checked = e.target.checked;
    setSelectAll(checked);
    setSelectedOfferingIds(checked ? offerings.map(o => o.offering_id) : []);
  };

  const handleOfferingToggle = (offeringId) => {
    setSelectedOfferingIds(prev => {
      const next = prev.includes(offeringId)
        ? prev.filter(id => id !== offeringId)
        : [...prev, offeringId];
      return next;
    });
    setSelectAll(false);
  };

  const handleSubmit = async () => {
    if (!title.trim()) { alert('الرجاء إدخال عنوان الكويز'); return; }
    if (!selectedSubject) { alert('الرجاء اختيار المادة'); return; }
    if (selectedOfferingIds.length === 0) { alert('يجب اختيار قسم واحد على الأقل.'); return; }
    setSubmitting(true);
    const formData = new FormData();
    formData.append('offering_ids', JSON.stringify(selectedOfferingIds));
    formData.append('creator_id', DOCTOR_ID);
    formData.append('title', title);
    formData.append('type', 'quiz');
    formData.append('description', description);
    formData.append('max_grade', maxGrade);
    if (file) formData.append('file', file);
    const res = await apiPostFormData('assignments', formData);
    if (res.success) {
      setTitle(''); setDescription(''); setMaxGrade(10); setFile(null);
      setSelectedSubject(''); setOfferings([]); setSelectedOfferingIds([]); setSelectAll(false);
      loadQuizzes();
    } else {
      alert(res.message || 'حدث خطأ في إنشاء الكويز');
    }
    setSubmitting(false);
  };

  const handleDeleteQuiz = async (quizId) => {
    if (!window.confirm('هل أنت متأكد من حذف هذا الكويز؟ سيتم حذف جميع الإجابات والدرجات والبيانات المرتبطة به.')) return;
    try {
      const res = await apiDelete(`assignments/${quizId}`);
      if (res.success) {
        loadQuizzes();
      } else {
        alert(res.message || 'حدث خطأ');
      }
    } catch (e) {
      alert('فشل الاتصال بالخادم');
    }
  };

  return (
    <div style={{ padding: "30px" }}>
      <h2>إدارة الكويزات</h2>

      {/* اختيار المادة */}
      <div style={{ marginBottom: "15px" }}>
        <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>المادة</label>
        <select
          value={selectedSubject}
          onChange={handleSubjectChange}
          style={{ padding: "8px 10px", borderRadius: "5px", border: "1px solid #ccc", minWidth: "250px" }}
        >
          <option value="">-- اختر المادة --</option>
          {subjects.map((s, i) => (
            <option key={i} value={s.subject_name}>{s.subject_name}</option>
          ))}
        </select>
      </div>

      {/* التخصصات (تظهر بعد اختيار المادة) */}
      {offerings.length > 0 && (
        <div style={{ marginBottom: "15px" }}>
          <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>التخصصات</label>
          <div style={{ background: "#fff", padding: "10px", borderRadius: "8px", border: "1px solid #ddd" }}>
            <label style={{ display: "block", marginBottom: "6px", cursor: "pointer" }}>
              <input type="checkbox" checked={selectAll} onChange={handleSelectAll} style={{ marginLeft: "8px" }} />
              <strong>الكل</strong>
            </label>
            {offerings.map((o, i) => (
              <label key={i} style={{ display: "block", marginBottom: "4px", cursor: "pointer", marginRight: "20px" }}>
                <input
                  type="checkbox"
                  checked={selectedOfferingIds.includes(o.offering_id)}
                  onChange={() => handleOfferingToggle(o.offering_id)}
                  style={{ marginLeft: "8px" }}
                />
                {o.department_name}
              </label>
            ))}
          </div>
        </div>
      )}

      {/* عنوان الكويز */}
      <div style={{ marginBottom: "15px" }}>
        <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>عنوان الكويز</label>
        <input
          type="text"
          placeholder="اسم الكويز"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          style={{ padding: "8px 10px", borderRadius: "5px", border: "1px solid #ccc", minWidth: "300px" }}
        />
      </div>

      {/* الوصف */}
      <div style={{ marginBottom: "15px" }}>
        <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>الوصف</label>
        <textarea
          placeholder="وصف الكويز (اختياري)"
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          rows={3}
          style={{ padding: "8px 10px", borderRadius: "5px", border: "1px solid #ccc", minWidth: "300px", resize: "vertical" }}
        />
      </div>

      {/* الدرجة القصوى */}
      <div style={{ marginBottom: "15px" }}>
        <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>الدرجة القصوى</label>
        <input
          type="number"
          value={maxGrade}
          onChange={(e) => setMaxGrade(Number(e.target.value))}
          min="1"
          style={{ padding: "8px 10px", borderRadius: "5px", border: "1px solid #ccc", width: "100px" }}
        />
      </div>

      {/* رفع ملف */}
      <div style={{ marginBottom: "15px" }}>
        <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>ملف (اختياري)</label>
        <input
          type="file"
          accept=".pdf"
          onChange={(e) => setFile(e.target.files ? e.target.files[0] : null)}
        />
        {file && <span style={{ marginRight: "10px", color: "#555" }}>{file.name}</span>}
      </div>

      <button
        onClick={handleSubmit}
        disabled={submitting}
        style={{
          background: primary,
          color: "#fff",
          border: "none",
          padding: "10px 20px",
          borderRadius: "8px",
          cursor: submitting ? "not-allowed" : "pointer",
          opacity: submitting ? 0.6 : 1,
        }}
      >
        {submitting ? 'جاري الإنشاء...' : 'إنشاء كويز'}
      </button>

      <hr style={{ margin: "25px 0" }} />

      <h3>الكويزات السابقة</h3>
      {quizzes.length === 0 ? (
        <p style={{ color: "#888" }}>لا توجد كويزات بعد</p>
      ) : (
        <table style={{ width: "100%", borderCollapse: "collapse", background: "#fff" }}>
          <thead>
            <tr style={{ background: "#f0f3f6" }}>
              <th style={{ border: "1px solid #ccc", padding: "8px" }}>العنوان</th>
              <th style={{ border: "1px solid #ccc", padding: "8px" }}>التخصصات</th>
              <th style={{ border: "1px solid #ccc", padding: "8px" }}>الدرجة</th>
              <th style={{ border: "1px solid #ccc", padding: "8px" }}>التاريخ</th>
              <th style={{ border: "1px solid #ccc", padding: "8px" }}>الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            {quizzes.map((q, i) => (
              <tr key={q.id || i}>
                <td style={{ border: "1px solid #ccc", padding: "8px" }}>{q.title}</td>
                <td style={{ border: "1px solid #ccc", padding: "8px" }}>{(q.department_names || []).join(' - ') || '-'}</td>
                <td style={{ border: "1px solid #ccc", padding: "8px" }}>{q.max_grade ?? '-'}</td>
                <td style={{ border: "1px solid #ccc", padding: "8px" }}>{q.created_at ? new Date(q.created_at).toLocaleDateString('ar-SA') : '-'}</td>
                <td style={{ border: "1px solid #ccc", padding: "8px" }}>
                  <button
                    onClick={() => handleDeleteQuiz(q.id)}
                    style={{
                      background: "#e74c3c",
                      color: "#fff",
                      border: "none",
                      padding: "4px 10px",
                      borderRadius: "4px",
                      fontSize: "11px",
                      cursor: "pointer",
                    }}
                  >
                    حذف
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
};
const QuizResultsPage = () => {
  const results = [
    { student: "Ali", grade: 8 },
    { student: "Sara", grade: 9 },
    { student: "Ahmed", grade: 7 },
  ];

  return (
    <div style={{ padding: "30px" }}>
      <h2>نتائج الكويز</h2>

      <table style={{ width: "100%", background: "#fff" }}>
        <thead>
          <tr>
            <th>الطالب</th>
            <th>الدرجة</th>
          </tr>
        </thead>

        <tbody>
          {results.map((r, i) => (
            <tr key={i}>
              <td>{r.student}</td>
              <td>{r.grade}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};
const AssignmentsPage = () => {
  const [subjects, setSubjects] = useState([]);
  const [assignments, setAssignments] = useState([]);
  const [selectedSubject, setSelectedSubject] = useState('');
  const [offerings, setOfferings] = useState([]);
  const [selectedOfferingIds, setSelectedOfferingIds] = useState([]);
  const [selectAll, setSelectAll] = useState(false);
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [maxGrade, setMaxGrade] = useState(10);
  const [dueDate, setDueDate] = useState('');
  const [file, setFile] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    apiPost('users/courses', { user_id: DOCTOR_ID, role: 'doctor' }).then(res => {
      if (res.status === 'success' && res.courses) setSubjects(res.courses);
    });
    loadAssignments();
  }, []);

  const loadAssignments = () => {
    apiGet(`doctor/assignments/${DOCTOR_ID}`).then(res => {
      if (res.success) {
        setAssignments(res.data || []);
      }
    });
  };

  const handleDeleteAssignment = async (assignmentId) => {
    if (!window.confirm('هل أنت متأكد من حذف هذا التكليف؟ سيتم حذف جميع التسليمات والدرجات المرتبطة به.')) return;
    try {
      const res = await apiDelete(`assignments/${assignmentId}`);
      if (res.success) {
        loadAssignments();
      } else {
        alert(res.message || 'حدث خطأ');
      }
    } catch (e) {
      alert('فشل الاتصال بالخادم');
    }
  };

  const handleSubjectChange = (e) => {
    const name = e.target.value;
    setSelectedSubject(name);
    const sub = subjects.find(s => s.subject_name === name);
    if (sub) {
      setOfferings(sub.offerings || []);
    } else {
      setOfferings([]);
    }
    setSelectedOfferingIds([]);
    setSelectAll(false);
  };

  const handleSelectAll = (e) => {
    const checked = e.target.checked;
    setSelectAll(checked);
    setSelectedOfferingIds(checked ? offerings.map(o => o.offering_id) : []);
  };

  const handleOfferingToggle = (offeringId) => {
    setSelectedOfferingIds(prev => {
      const next = prev.includes(offeringId)
        ? prev.filter(id => id !== offeringId)
        : [...prev, offeringId];
      return next;
    });
    setSelectAll(false);
  };

  const handleSubmit = async () => {
    if (!title.trim()) { alert('الرجاء إدخال عنوان التكليف'); return; }
    if (!dueDate) { alert('الرجاء إدخال موعد التسليم'); return; }
    if (!selectedSubject) { alert('الرجاء اختيار المادة'); return; }
    if (selectedOfferingIds.length === 0) { alert('يجب اختيار قسم واحد على الأقل.'); return; }
    setSubmitting(true);
    const formData = new FormData();
    formData.append('offering_ids', JSON.stringify(selectedOfferingIds));
    formData.append('creator_id', DOCTOR_ID);
    formData.append('title', title);
    formData.append('type', 'assignment');
    formData.append('description', description);
    formData.append('max_grade', maxGrade);
    formData.append('due_date', dueDate);
    if (file) formData.append('file', file);
    const res = await apiPostFormData('assignments', formData);
    if (res.success) {
      setTitle(''); setDescription(''); setMaxGrade(10); setDueDate(''); setFile(null);
      setSelectedSubject(''); setOfferings([]); setSelectedOfferingIds([]); setSelectAll(false);
      loadAssignments();
    } else {
      alert(res.message || 'حدث خطأ في إنشاء التكليف');
    }
    setSubmitting(false);
  };

  return (
    <div style={{ padding: "30px" }}>
      <h2>التكاليف</h2>

      {/* اختيار المادة */}
      <div style={{ marginBottom: "15px" }}>
        <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>المادة</label>
        <select
          value={selectedSubject}
          onChange={handleSubjectChange}
          style={{ padding: "8px 10px", borderRadius: "5px", border: "1px solid #ccc", minWidth: "250px" }}
        >
          <option value="">-- اختر المادة --</option>
          {subjects.map((s, i) => (
            <option key={i} value={s.subject_name}>{s.subject_name}</option>
          ))}
        </select>
      </div>

      {/* التخصصات (تظهر بعد اختيار المادة) */}
      {offerings.length > 0 && (
        <div style={{ marginBottom: "15px" }}>
          <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>التخصصات</label>
          <div style={{ background: "#fff", padding: "10px", borderRadius: "8px", border: "1px solid #ddd" }}>
            <label style={{ display: "block", marginBottom: "6px", cursor: "pointer" }}>
              <input type="checkbox" checked={selectAll} onChange={handleSelectAll} style={{ marginLeft: "8px" }} />
              <strong>الكل</strong>
            </label>
            {offerings.map((o, i) => (
              <label key={i} style={{ display: "block", marginBottom: "4px", cursor: "pointer", marginRight: "20px" }}>
                <input
                  type="checkbox"
                  checked={selectedOfferingIds.includes(o.offering_id)}
                  onChange={() => handleOfferingToggle(o.offering_id)}
                  style={{ marginLeft: "8px" }}
                />
                {o.department_name}
              </label>
            ))}
          </div>
        </div>
      )}

      {/* العنوان */}
      <div style={{ marginBottom: "15px" }}>
        <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>عنوان التكليف</label>
        <input
          type="text"
          placeholder="عنوان التكليف"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          style={{ padding: "8px", borderRadius: "5px", border: "1px solid #ccc", minWidth: "300px" }}
        />
      </div>

      {/* الوصف */}
      <div style={{ marginBottom: "15px" }}>
        <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>الوصف</label>
        <textarea
          placeholder="وصف التكليف (اختياري)"
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          rows={3}
          style={{ padding: "8px 10px", borderRadius: "5px", border: "1px solid #ccc", minWidth: "300px", resize: "vertical" }}
        />
      </div>

      {/* الدرجة القصوى */}
      <div style={{ marginBottom: "15px" }}>
        <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>الدرجة القصوى</label>
        <input
          type="number"
          value={maxGrade}
          onChange={(e) => setMaxGrade(Number(e.target.value))}
          min="1"
          style={{ padding: "8px", borderRadius: "5px", border: "1px solid #ccc", width: "100px" }}
        />
      </div>

      {/* موعد التسليم */}
      <div style={{ marginBottom: "15px" }}>
        <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>موعد التسليم</label>
        <input
          type="date"
          value={dueDate}
          onChange={(e) => setDueDate(e.target.value)}
          style={{ padding: "8px", borderRadius: "5px", border: "1px solid #ccc" }}
        />
      </div>

      {/* رفع ملف */}
      <div style={{ marginBottom: "15px" }}>
        <label style={{ fontWeight: "bold", display: "block", marginBottom: "5px" }}>ملف (اختياري)</label>
        <input
          type="file"
          accept=".pdf"
          onChange={(e) => setFile(e.target.files ? e.target.files[0] : null)}
        />
        {file && <span style={{ marginRight: "10px", color: "#555" }}>{file.name}</span>}
      </div>

      <button
        onClick={handleSubmit}
        disabled={submitting}
        style={{
          background: primary,
          color: "#fff",
          border: "none",
          padding: "10px 20px",
          borderRadius: "8px",
          cursor: submitting ? "not-allowed" : "pointer",
          opacity: submitting ? 0.6 : 1,
        }}
      >
        {submitting ? 'جاري الإنشاء...' : 'إضافة تكليف'}
      </button>

      <hr style={{ margin: "25px 0" }} />

      <h3>التكاليف السابقة</h3>
      {assignments.length === 0 ? (
        <p style={{ color: "#888" }}>لا يوجد تكاليف حالياً</p>
      ) : (
        <table style={{ width: "100%", borderCollapse: "collapse", background: "#fff" }}>
          <thead>
            <tr style={{ background: "#f0f3f6" }}>
              <th style={{ border: "1px solid #ccc", padding: "8px" }}>العنوان</th>
              <th style={{ border: "1px solid #ccc", padding: "8px" }}>التخصصات</th>
              <th style={{ border: "1px solid #ccc", padding: "8px" }}>الدرجة</th>
              <th style={{ border: "1px solid #ccc", padding: "8px" }}>موعد التسليم</th>
              <th style={{ border: "1px solid #ccc", padding: "8px" }}>الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            {assignments.map((a, i) => (
              <tr key={a.id || i}>
                <td style={{ border: "1px solid #ccc", padding: "8px" }}>{a.title}</td>
                <td style={{ border: "1px solid #ccc", padding: "8px" }}>{(a.department_names || []).join(' - ') || '-'}</td>
                <td style={{ border: "1px solid #ccc", padding: "8px" }}>{a.max_grade ?? '-'}</td>
                <td style={{ border: "1px solid #ccc", padding: "8px" }}>{a.due_date || '-'}</td>
                <td style={{ border: "1px solid #ccc", padding: "8px" }}>
                  <button
                    onClick={() => handleDeleteAssignment(a.id)}
                    style={{
                      background: "#e74c3c",
                      color: "#fff",
                      border: "none",
                      padding: "4px 10px",
                      borderRadius: "4px",
                      fontSize: "11px",
                      cursor: "pointer",
                    }}
                  >
                    حذف
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
};
const SubmissionsPage = () => {
  const [subs, setSubs] = useState([
    { id: 1, student: "Ali", file: "assignment.pdf", grade: "" },
  ]);

  const gradeStudent = (id: number, grade: string) => {
  setSubs(
    subs.map((s) =>
      s.id === id ? { ...s, grade } : s
    )
  );
};

  return (
    <div style={{ padding: "30px" }}>
      <h2>تسليمات الطلاب</h2>

      {subs.map((s) => (
        <div key={s.id} style={{ marginBottom: "20px" }}>
          <p>
            {s.student} - {s.file}
          </p>

          <input
            type="number"
            placeholder="الدرجة"
            onChange={(e) => gradeStudent(s.id, e.target.value)}
          />

          <span> الدرجة: {s.grade}</span>
        </div>
      ))}
    </div>
  );
};
const FinalGradesPage = () => {
  const [grades, setGrades] = useState<
    { student: string; mid: string; practical: string; total?: number; gradeLetter?: string }[]
  >([
    { student: "Ali", mid: "", practical: "" },
    { student: "Sara", mid: "", practical: "" },
  ]);

  // تحديث الدرجة وحساب المجموع والتقدير
  const updateGrade = (index: number, field: "mid" | "practical", value: string) => {
    const newGrades = [...grades];
    newGrades[index][field] = value;

    // حساب المجموع
    const midNum = parseFloat(newGrades[index].mid) || 0;
    const practicalNum = parseFloat(newGrades[index].practical) || 0;
    const total = midNum + practicalNum;
    newGrades[index].total = total;

    // حساب التقدير
    let gradeLetter = "";
    if (total >= 90) gradeLetter = "م";
    else if (total >= 80) gradeLetter = "ج ج";
    else if (total >= 70) gradeLetter = "ج";
    else if (total >= 60) gradeLetter = "ل";
    else gradeLetter = "F";

    newGrades[index].gradeLetter = gradeLetter;

    setGrades(newGrades);
  };

  return (
    <div style={{ padding: "30px" }}>
      <h2>إدخال درجات الطلاب</h2>

      <table style={{ width: "100%", background: "#fff", borderCollapse: "collapse" }}>
        <thead>
          <tr style={{ background: "#f0f3f6" }}>
            <th style={{ padding: "8px" }}>الطالب</th>
            <th style={{ padding: "8px" }}>الميد</th>
            <th style={{ padding: "8px" }}>العملي</th>
            <th style={{ padding: "8px" }}>المجموع</th>
            <th style={{ padding: "8px" }}>التقدير</th>
          </tr>
        </thead>
        <tbody>
          {grades.map((g, i) => (
            <tr key={i}>
              <td style={{ padding: "8px" }}>{g.student}</td>
              <td style={{ padding: "8px" }}>
                <input
                  type="number"
                  value={g.mid}
                  onChange={(e) => updateGrade(i, "mid", e.target.value)}
                  style={{ width: "60px" }}
                />
              </td>
              <td style={{ padding: "8px" }}>
                <input
                  type="number"
                  value={g.practical}
                  onChange={(e) => updateGrade(i, "practical", e.target.value)}
                  style={{ width: "60px" }}
                />
              </td>
              <td style={{ padding: "8px", textAlign: "center" }}>{g.total ?? 0}</td>
              <td style={{ padding: "8px", textAlign: "center" }}>{g.gradeLetter ?? "-"}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};



/* ================= App ================= */
const DoctorApp = () => (
  <Router>
    <div
      dir="rtl"
      style={{
        display: "flex",
        flexDirection: "row-reverse", // Sidebar على اليمين
        background: "#f4f6f9",
        minHeight: "100vh",
      }}
    >
      <DoctorSidebar />
      <div style={{ flex: 1 }}>
        <TopNavbar />
       <Routes>
  <Route path="/" element={<DoctorDashboard />} />
  <Route path="/subjects" element={<SubjectsPage />} />
  <Route path="/attendance" element={<AttendancePage />} />
  <Route path="/quizzes" element={<QuizPage />} />
  <Route path="/assignments" element={<AssignmentsPage />} />
  <Route path="/final-grades" element={<FinalGradesPage />} />
  <Route path="/notifications" element={<EmptyPage title="الإشعارات" />} />
</Routes>
      </div>
    </div>
  </Router>
);

export default DoctorApp;