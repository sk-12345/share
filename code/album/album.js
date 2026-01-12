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

function mediaThumb(p) {
    const type = String(p.media_type || "");
    const url = String(p.media_url || "");
    if (type.startsWith("video/")) {
        return `<video class="media" src="${esc(url)}" controls preload="metadata"></video>`;
    }
    return `<img class="media" src="${esc(url)}" alt="">`;
}

function albumCard(a) {
    const items = a.photos ?? [];

    const thumbs = items.map((p) => `
    <div class="thumb">
      <label class="pick">
        <input type="checkbox" class="pick-photo" data-photo-id="${esc(p.id)}">
        選択
      </label>

      ${mediaThumb(p)}

      <div class="thumb-actions">
        <a class="mini" href="${esc(p.download_url)}">1件DL</a>

        ${a.can_delete_photo ? `
          <button class="mini danger" data-action="delete_photo" data-photo-id="${esc(p.id)}">削除</button>
        ` : ``}
      </div>
    </div>
  `).join("");

    return `
    <div class="card" data-album-id="${esc(a.id)}">
      <div class="card-head">
        <h3>${esc(a.title)}</h3>

        <div class="actions">
          ${a.can_edit ? `<button data-action="edit_album" data-album-id="${esc(a.id)}">編集</button>` : ``}
          ${a.can_edit ? `<button data-action="add_photos" data-album-id="${esc(a.id)}">追加</button>` : ``}
          <a class="btn" href="${esc(a.zip_url)}">アルバムZIP</a>
          ${a.can_delete ? `<button class="danger" data-action="delete_album" data-album-id="${esc(a.id)}">削除</button>` : ``}
        </div>
      </div>

      <p class="desc">${esc(a.description).replaceAll("\n", "<br>")}</p>
      <small>作成：${esc(a.created_at)}</small>

      <div class="select-actions">
        <button class="mini" data-action="select_all">全選択</button>
        <button class="mini" data-action="select_none">全解除</button>
        <button class="mini" data-action="download_selected_zip">選択ZIP</button>
      </div>

      <div class="thumbs">${thumbs || `<p class="muted">写真/動画なし</p>`}</div>
    </div>
  `;
}

function render(data) {
    if (data?.error) {
        listEl.innerHTML = `<pre style="background:#111;color:#0f0;padding:12px;white-space:pre-wrap;">${esc(JSON.stringify(data, null, 2))}</pre>`;
        return;
    }

    createArea.style.display = data.me?.can_create ? "block" : "none";

    const albums = data.albums ?? [];
    if (albums.length === 0) {
        listEl.innerHTML = `<p class="muted">アルバムがありません。</p>`;
        return;
    }
    listEl.innerHTML = albums.map(albumCard).join("");
}

async function load() {
    try {
        const res = await fetch(API_URL, { cache: "no-store" });
        if (res.status === 401) {
            location.href = "../login/login.php";
            return;
        }
        const data = await res.json();
        render(data);
    } catch (e) {
        console.error(e);
        listEl.innerHTML = `<pre style="background:#111;color:#0f0;padding:12px;white-space:pre-wrap;">${esc(e?.stack || String(e))}</pre>`;
    }
}

// 作成（写真/動画）
createForm?.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    createMsg.textContent = "";

    const fd = new FormData(createForm);
    fd.append("action", "create_album");

    const res = await fetch(API_URL, { method: "POST", body: fd });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data?.ok !== true) {
        createMsg.textContent = "作成に失敗しました";
        console.log(data);
        return;
    }

    createForm.reset();
    createMsg.textContent = "作成しました！";
    await load();
});

// 一覧側ボタン（委譲）
listEl.addEventListener("click", async (ev) => {
    const btn = ev.target.closest("button");
    if (!btn) return;

    const card = btn.closest(".card");
    const albumId = card?.dataset?.albumId;

    const action = btn.dataset.action;

    if (action === "delete_photo") {
        const photoId = btn.dataset.photoId;
        if (!confirm("このデータを削除しますか？")) return;

        const fd = new FormData();
        fd.append("action", "delete_photo");
        fd.append("photo_id", photoId);

        const res = await fetch(API_URL, { method: "POST", body: fd });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data?.ok !== true) alert("削除に失敗しました");
        await load();
        return;
    }

    if (action === "delete_album") {
        if (!confirm("アルバムを削除しますか？（中の写真/動画も全部消えます）")) return;

        const fd = new FormData();
        fd.append("action", "delete_album");
        fd.append("album_id", albumId);

        const res = await fetch(API_URL, { method: "POST", body: fd });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data?.ok !== true) alert("削除に失敗しました");
        await load();
        return;
    }

    if (action === "edit_album") {
        const curTitle = card?.querySelector("h3")?.textContent ?? "";
        const curDesc = card?.querySelector(".desc")?.innerText ?? "";

        const newTitle = prompt("題名を変更", curTitle);
        if (newTitle === null) return;

        const newDesc = prompt("説明を変更", curDesc);
        if (newDesc === null) return;

        const fd = new FormData();
        fd.append("action", "update_album");
        fd.append("album_id", albumId);
        fd.append("title", newTitle);
        fd.append("description", newDesc);

        const res = await fetch(API_URL, { method: "POST", body: fd });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data?.ok !== true) alert("編集に失敗しました");
        await load();
        return;
    }

    if (action === "add_photos") {
        const input = document.createElement("input");
        input.type = "file";
        input.accept = "image/*,video/*";
        input.multiple = true;

        input.onchange = async () => {
            const fd = new FormData();
            fd.append("action", "add_photos");
            fd.append("album_id", albumId);
            [...input.files].forEach((f) => fd.append("files[]", f));

            const res = await fetch(API_URL, { method: "POST", body: fd });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data?.ok !== true) alert("追加に失敗しました");
            await load();
        };

        input.click();
        return;
    }

    if (action === "select_all" || action === "select_none") {
        const checks = [...card.querySelectorAll(".pick-photo")];
        const on = action === "select_all";
        checks.forEach((c) => (c.checked = on));
        return;
    }

    if (action === "download_selected_zip") {
        const ids = [...card.querySelectorAll(".pick-photo:checked")].map((c) => c.dataset.photoId);
        if (ids.length === 0) {
            alert("まず選択して！");
            return;
        }

        const fd = new FormData();
        fd.append("action", "download_selected_zip");
        ids.forEach((id) => fd.append("photo_ids[]", id));

        const res = await fetch(API_URL, { method: "POST", body: fd });
        if (!res.ok) {
            alert("ZIP作成に失敗しました");
            return;
        }

        const blob = await res.blob();
        const url = URL.createObjectURL(blob);

        const a = document.createElement("a");
        a.href = url;
        const title = card.querySelector("h3")?.textContent?.trim() || "selected";
        a.download = `${title}_selected.zip`;

        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }
});

load();
