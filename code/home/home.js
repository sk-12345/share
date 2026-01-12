const API_URL = "home_api.php"; // 同じフォルダならこれでOK

const welcomeTitle = document.getElementById("welcomeTitle");
const roleText = document.getElementById("roleText");
const menuArea = document.getElementById("menuArea");

function escapeHtml(str) {
    return String(str ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function buildMenu(isAdminOrSystem) {
    const items = [];

    if (isAdminOrSystem) {
        items.push({ href: "../register/register.html", text: "新規アカウント作成" });
        items.push({ href: "../account/account.html", text: "アカウント管理" });
    }

    items.push({ href: "../album/album.html", text: "共有", extraClass: "main-card" });
    items.push({ href: "../password/password.html", text: "パスワード変更" });
    items.push({ href: "../logout.php", text: "ログアウト", extraClass: "logout-card" });

    menuArea.innerHTML = "";
    items.forEach(item => {
        const a = document.createElement("a");
        a.href = item.href;
        a.textContent = item.text;
        a.className = ["card", item.extraClass].filter(Boolean).join(" ");
        menuArea.appendChild(a);
    });
}


async function loadHome() {
    const res = await fetch(API_URL, { cache: "no-store" });

    if (res.status === 401) {
        location.href = "../login/login.php";
        return;
    }

    if (!res.ok) {
        welcomeTitle.innerHTML = "読み込みに失敗しました";
        return;
    }

    const data = await res.json();

    const fullname = data.user?.fullname ?? "";
    const roleName = data.user?.role_name ?? "";
    const isAdminOrSystem = !!data.flags?.is_admin_or_system;

    welcomeTitle.innerHTML = `ようこそ、<br>${escapeHtml(fullname)} さん`;
    roleText.textContent = `権限：${roleName}`;

    buildMenu(isAdminOrSystem);
}

loadHome();
