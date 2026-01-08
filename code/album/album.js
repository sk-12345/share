const API_URL = "album_api.php";

const createArea = document.getElementById("createArea");
const createForm = document.getElementById("createForm");
const createMsg = document.getElementById("createMsg");
const listEl = document.getElementById("albumList");

function esc(str) {
    return String(str ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function albumCard(a) {
    const photos = a.photos ?? [];
    const thumbs = photos.slice(0, 6).map(p => `
    <div class="thumb">
      <img src="${esc(p.image_url)}" alt="">
      ${a.can_delete_photo ? `<button class="mini-del" data-action="delete_photo" data-photo-id="${esc(p.id)}">×</button>` : ``}
    </div>
  `).join("");

    const editBtns = a.can_edit ? `
    <button data-action="edit_album" data-album-id="${esc(a.id)}">編集</button>
  ` : ``;

    const delBtn = a.can_delete ? `
    <button class="danger" data-action="delete_album" data-album-id="${esc(a.id)}">アルバム削除</button>
  ` : ``;

    return `
    <div class="card">
      <div class="card-head">
        <h3>${esc(a.title)}</h3>
        <div class="actions">
          ${editBtns}
          ${delBtn}
        </div>
      </div>

      <p class="desc">${esc(a.description).replaceAll("\n", "<br>")}</p>
      <small>作成：${esc(a.created_at)}</small>

      <div class="thumbs">${thumbs || `<p class="muted">写真なし</p>`}</div>
    </div>
  `;
}

function render(data) {
    if (data.me?.can_create) createArea.style.display = "block";
    else createArea.style.display = "none";

    const albums = data.albums ?? [];
    if (albums.length === 0) {
        listEl.innerHTML = `<p class="muted">アルバムがありません。</p>`;
        return;
    }
    listEl.innerHTML = albums.map(albumCard).join("");
}

async function load() {
    const res = await fetch(API_URL, { cache: "no-store" });

    if (res.status === 401) {
        location.href = "../login/login.php";
        return;
    }

    const data = await res.json();
    render(data);
}

// 作成（複数写真）
createForm?.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    createMsg.textContent = "";

    const fd = new FormData(createForm);
    fd.append("action", "create_album");

    const res = await fetch(API_URL, { method: "POST", body: fd });
    if (!res.ok) {
        createMsg.textContent = "作成に失敗しました";
        return;
    }

    createForm.reset();
    createMsg.textContent = "作成しました！";
    await load();
});

// 一覧側のボタン（削除/編集）をイベント委譲で処理
listEl.addEventListener("click", async (ev) => {
    const btn = ev.target.closest("button");
    if (!btn) return;

    const action = btn.dataset.action;

    // 写真削除
    if (action === "delete_photo") {
        const photoId = btn.dataset.photoId;
        if (!confirm("この写真を削除しますか？")) return;

        const fd = new FormData();
        fd.append("action", "delete_photo");
        fd.append("photo_id", photoId);

        const res = await fetch(API_URL, { method: "POST", body: fd });
        if (!res.ok) alert("削除に失敗しました");
        await load();
        return;
    }

    // アルバム削除
    if (action === "delete_album") {
        const albumId = btn.dataset.albumId;
        if (!confirm("アルバムを削除しますか？（中の写真も全部消えます）")) return;

        const fd = new FormData();
        fd.append("action", "delete_album");
        fd.append("album_id", albumId);

        const res = await fetch(API_URL, { method: "POST", body: fd });
        if (!res.ok) alert("削除に失敗しました");
        await load();
        return;
    }

    // アルバム編集（簡易prompt版）
    if (action === "edit_album") {
        const albumId = btn.dataset.albumId;

        // 今の表示から探して、初期値に使う（雑でもOKならこれが速い）
        const card = btn.closest(".card");
        const curTitle = card?.querySelector("h3")?.textContent ?? "";
        const curDesc = card?.querySelector(".desc")?.innerText ?? "";

        const newTitle = prompt("題名（フォルダ名）を変更", curTitle);
        if (newTitle === null) return;

        const newDesc = prompt("説明（何をしたか）を変更", curDesc);
        if (newDesc === null) return;

        const fd = new FormData();
        fd.append("action", "update_album");
        fd.append("album_id", albumId);
        fd.append("title", newTitle);
        fd.append("description", newDesc);

        const res = await fetch(API_URL, { method: "POST", body: fd });
        if (!res.ok) alert("編集に失敗しました");
        await load();
        return;
    }
});

load();
