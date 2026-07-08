import "./App.css";
import logo from "./assets/logo.jpg";

function App() {
  return (
    <div className="container">
      <div className="login-card">

        <img src={logo} alt="Logo" className="logo" />

        <h2>تسجيل الدخول</h2>
        <p className="subtitle">مرحباً بكم </p>

        <form>
          <div className="input-group">
            <label>البريد الإلكتروني</label>
            <input type="email" placeholder="name@example.com  " />
          </div>

          <div className="input-group">
            <label>كلمة المرور</label>
            <input type="password" placeholder="*******" />
          </div>

          <div className="forgot-password">
            <a href="#">هل نسيت كلمة المرور؟</a>
          </div>

          <button type="submit">دخول</button>
        </form>
      </div>
    </div>
  );
}

export default App;