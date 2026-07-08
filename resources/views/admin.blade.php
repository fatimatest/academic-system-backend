import React, { useState } from "react";
import logo from "./assets/logo.jpg";

const CollegeManager: React.FC = () => {

/* ================= STATE ================= */

const [page,setPage] = useState("dashboard")

const [subjects,setSubjects] = useState<any[]>([])
const [departments,setDepartments] = useState<any[]>([])
const [doctors,setDoctors] = useState<any[]>([])
const [semesters,setSemesters] = useState<any[]>([])

const [showModal,setShowModal] = useState(false)
const [modalType,setModalType] = useState("")

const [form,setForm] = useState<any>({})

/* ================= OPEN MODAL ================= */

const openModal=(type:string)=>{
setModalType(type)
setForm({})
setShowModal(true)
}

/* ================= SAVE ================= */

const saveData=()=>{

if(modalType==="subject"){
setSubjects([...subjects,{ id:subjects.length+1,...form }])
}

if(modalType==="doctor"){
setDoctors([...doctors,{ id:doctors.length+1,...form }])
}

if(modalType==="department"){
setDepartments([...departments,{ id:departments.length+1,...form }])
}

if(modalType==="semester"){
setSemesters([...semesters,{ id:semesters.length+1,...form }])
}

setShowModal(false)

}

/* ================= DELETE ================= */

const deleteItem=(id:number,type:string)=>{

if(type==="subject"){
setSubjects(subjects.filter(i=>i.id!==id))
}

if(type==="doctor"){
setDoctors(doctors.filter(i=>i.id!==id))
}

if(type==="department"){
setDepartments(departments.filter(i=>i.id!==id))
}

if(type==="semester"){
setSemesters(semesters.filter(i=>i.id!==id))
}

}

/* ================= DASHBOARD ================= */

const dashboard=(

<div>

<h2 style={{marginBottom:"20px"}}>لوحة التحكم</h2>

<div style={cards}>

<div style={card}>
<h3>المواد</h3>
<p style={number}>{subjects.length}</p>
</div>

<div style={card}>
<h3>الدكاترة</h3>
<p style={number}>{doctors.length}</p>
</div>

<div style={card}>
<h3>الأقسام</h3>
<p style={number}>{departments.length}</p>
</div>

<div style={card}>
<h3>الأترام</h3>
<p style={number}>{semesters.length}</p>
</div>

</div>

</div>

)

/* ================= TABLE ================= */

const tableHeader=(headers:string[])=>(

<thead style={{background:"#f1f5f9"}}>

<tr>

{headers.map(h=><th key={h} style={th}>{h}</th>)}

<th style={th}>إجراءات</th>

</tr>

</thead>

)

// ================= ACTION BUTTONS =================
const actionBtns = (id: number, type: string) => (
  <td>
    <div
      style={{
        display: "flex",
        gap: type === "department" ? "2px" : "6px", // تصغير الفجوة في إدارة الأقسام
        justifyContent: "center",
      }}
    >
      <button
        style={{
          padding: type === "department" ? "2px 4px" : "4px 8px",
          fontSize: type === "department" ? "10px" : "12px",
          border: "none",
          background: "#2E8B86",
          borderRadius: "4px",
          cursor: "pointer",
          height: type === "department" ? "18px" : "24px",
          minWidth: type === "department" ? "35px" : "50px",
        }}
      >
        تعديل
      </button>

      <button
        style={{
          padding: type === "department" ? "2px 4px" : "4px 8px",
          fontSize: type === "department" ? "10px" : "12px",
          border: "none",
          background: "#f52323",
          borderRadius: "4px",
          cursor: "pointer",
          height: type === "department" ? "18px" : "24px",
          minWidth: type === "department" ? "35px" : "50px",
        }}
        onClick={() => deleteItem(id, type)}
      >
        حذف
      </button>
    </div>
  </td>
);
/* ================= SUBJECT PAGE ================= */

const subjectPage=(

<div>

<h2>إدارة المواد</h2>

<button style={button} onClick={()=>openModal("subject")}>
إضافة مادة
</button>

<table style={table}>

{tableHeader(["ID","المادة","الكود","الدكتور","الترم"])}

<tbody>

{subjects.map(s=>(

<tr key={s.id}>

<td>{s.id}</td>
<td>{s.name}</td>
<td>{s.code}</td>
<td>{s.doctor}</td>
<td>{s.term}</td>

{actionBtns(s.id,"subject")}

</tr>

))}

</tbody>

</table>

</div>

)

/* ================= DOCTOR PAGE ================= */

const doctorPage=(

<div>

<h2>إدارة الدكاترة</h2>

<button style={button} onClick={()=>openModal("doctor")}>
إضافة دكتور
</button>

<table style={table}>

{tableHeader(["ID","الاسم","البريد","القسم"])}

<tbody>

{doctors.map(d=>(

<tr key={d.id}>

<td>{d.id}</td>
<td>{d.name}</td>
<td>{d.email}</td>
<td>{d.department}</td>

{actionBtns(d.id,"doctor")}

</tr>

))}

</tbody>

</table>

</div>

)

/* ================= DEPARTMENT PAGE ================= */

const departmentPage=(

<div>

<h2>إدارة الأقسام</h2>

<button style={button} onClick={()=>openModal("department")}>
إضافة قسم
</button>

<table style={table}>

{tableHeader(["ID","القسم"])}

<tbody>

{departments.map(d=>(

<tr key={d.id}>

<td>{d.id}</td>
<td>{d.name}</td>

{actionBtns(d.id,"department")}

</tr>

))}

</tbody>

</table>

</div>

)

/* ================= SEMESTER PAGE ================= */

const semesterPage=(

<div>

<h2>إدارة الأترام</h2>

<button style={button} onClick={()=>openModal("semester")}>
إضافة ترم
</button>

<table style={table}>

{tableHeader(["ID","الترم","السنة"])}

<tbody>

{semesters.map(s=>(

<tr key={s.id}>

<td>{s.id}</td>
<td>{s.term}</td>
<td>{s.year}</td>

{actionBtns(s.id,"semester")}

</tr>

))}

</tbody>

</table>

</div>

)

/* ================= RENDER ================= */

const renderPage=()=>{

if(page==="dashboard") return dashboard
if(page==="subjects") return subjectPage
if(page==="doctors") return doctorPage
if(page==="departments") return departmentPage
if(page==="semesters") return semesterPage

}

/* ================= RETURN ================= */

return(

<div style={container}>

{/* SIDEBAR */}

<div style={sidebar}>

<img src={logo} style={{width:"120px",marginBottom:"30px"}}/>

<button
style={page==="dashboard"?activeMenu:menuBtn}
onClick={()=>setPage("dashboard")}
>
لوحة التحكم
</button>

<button
style={page==="subjects"?activeMenu:menuBtn}
onClick={()=>setPage("subjects")}
>
إدارة المواد
</button>

<button
style={page==="doctors"?activeMenu:menuBtn}
onClick={()=>setPage("doctors")}
>
إدارة الدكاترة
</button>

<button
style={page==="departments"?activeMenu:menuBtn}
onClick={()=>setPage("departments")}
>
إدارة الأقسام
</button>

<button
style={page==="semesters"?activeMenu:menuBtn}
onClick={()=>setPage("semesters")}
>
إدارة الأترام
</button>

</div>

{/* MAIN */}

<div style={main}>

<div style={topbar}>

<div>
<h3>نظام إدارة الكلية</h3>
<span style={{color:"#777"}}>2024 - 2025</span>
</div>

<div style={userBox}>
<div style={avatar}></div>
<div>
<div>أحمد المدير</div>
<small style={{color:"#777"}}>مدير الكلية</small>
</div>
</div>

</div>

<div style={{marginTop:"30px"}}>

{renderPage()}

</div>

</div>

{/* MODAL */}

{showModal && (

<div style={modalBg}>

<div style={modal}>

<h3>إضافة</h3>

{modalType==="subject" && (

<>

<input placeholder="اسم المادة" onChange={e=>setForm({...form,name:e.target.value})}/>
<input placeholder="الكود" onChange={e=>setForm({...form,code:e.target.value})}/>
<input placeholder="الدكتور" onChange={e=>setForm({...form,doctor:e.target.value})}/>
<input placeholder="الترم" onChange={e=>setForm({...form,term:e.target.value})}/>

</>

)}

{modalType==="doctor" && (

<>

<input placeholder="اسم الدكتور" onChange={e=>setForm({...form,name:e.target.value})}/>
<input placeholder="البريد" onChange={e=>setForm({...form,email:e.target.value})}/>
<input placeholder="القسم" onChange={e=>setForm({...form,department:e.target.value})}/>

</>

)}

{modalType==="department" && (

<input placeholder="اسم القسم" onChange={e=>setForm({...form,name:e.target.value})}/>

)}

{modalType==="semester" && (

<>

<input placeholder="الترم" onChange={e=>setForm({...form,term:e.target.value})}/>
<input placeholder="السنة" onChange={e=>setForm({...form,year:e.target.value})}/>

</>

)}

<div style={{display:"flex",gap:"10px",marginTop:"10px"}}>

<button style={button} onClick={saveData}>
حفظ
</button>

<button style={button} onClick={()=>setShowModal(false)}>
إلغاء
</button>

</div>

</div>

</div>

)}

</div>

)

}

/* ================= STYLES ================= */

/* ================= STYLES ================= */

const container = {
  display: "flex",
  direction: "rtl" as const,
  background: "#f4f6fb",
  minHeight: "100vh",
  fontFamily: "sans-serif",
  color: "black",
};

const sidebar = {
  width: "240px",
  background: "white",
  padding: "25px",
  display: "flex",
  flexDirection: "column" as const,
  gap: "10px",
  borderLeft: "1px solid #eee",
};

const menuBtn = {
  padding: "12px",
  background: "transparent",
  border: "none",
  textAlign: "right" as const,
  cursor: "pointer",
  borderRadius: "8px",
  color: "black", // <-- تم التعديل هنا
};

const activeMenu = {
  padding: "12px",
  background: "#e0f2f1",
  border: "none",
  textAlign: "right" as const,
  cursor: "pointer",
  borderRadius: "8px",
  fontWeight: "bold",
  color: "black", // <-- تم التعديل هنا
};

const main = {
  flex: 1,
  padding: "30px",
};

const topbar = {
  display: "flex",
  justifyContent: "space-between",
  alignItems: "center",
  background: "white",
  padding: "15px 25px",
  borderRadius: "10px",
  boxShadow: "0 2px 10px rgba(0,0,0,0.05)",
};

const userBox = {
  display: "flex",
  alignItems: "center",
  gap: "10px",
};

const avatar = {
  width: "40px",
  height: "40px",
  borderRadius: "50%",
  background: "#ddd",
};

const cards = {
  display: "flex",
  gap: "20px",
};

const card = {
  background: "white",
  padding: "25px",
  borderRadius: "10px",
  width: "180px",
  textAlign: "center" as const,
  boxShadow: "0 2px 10px rgba(0,0,0,0.05)",
};

const number = {
  fontSize: "26px",
  fontWeight: "bold",
};

const table = {
  width: "100%",
  marginTop: "20px",
  background: "white",
  borderCollapse: "collapse" as const,
};

const th = {
  padding: "10px",
};

const button = {
  padding: "8px 14px",
  marginTop: "10px",
  cursor: "pointer",
};

// أزرار تعديل وحذف صغيرة ومتناسقة مع إدارة المواد
const smallBtn = {
  padding: "4px 8px",  // نفس البادينج في إدارة المواد
  fontSize: "12px",    // نفس حجم الخط
  border: "none",
  background: "#e5e7eb",
  borderRadius: "6px",
  cursor: "pointer",
  height: "24px",       // طول مناسب مثل إدارة المواد
  minWidth: "50px",     // عرض مناسب
};

const deleteBtn = {
  ...smallBtn,
  background: "#fecaca",
};

const modalBg = {
  position: "fixed" as const,
  top: 0,
  left: 0,
  width: "100%",
  height: "100%",
  background: "rgba(0,0,0,0.4)",
  display: "flex",
  justifyContent: "center",
  alignItems: "center",
};

const modal = {
  background: "white",
  padding: "20px",
  borderRadius: "8px",
  width: "300px",
  display: "flex",
  flexDirection: "column" as const,
  gap: "10px",
};

export default CollegeManager