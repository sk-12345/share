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

    const thumbs = photos
        .map(
            (p) => `
      <div class="thumb">
        <label class="pick">
          <input type="checkbox" class="pick-photo" data-photo-id="${esc(p.id)}">
          選択
        </label>

        <img src="${esc(p.image_url)}" alt="">

        <div class="thumb-actions">
          <a class="mini" href="${esc(p.download_url ?? (API_URL + "?action=download_photo&photo_id=" + p.id))}">
            1枚DL
          </a>

          ${a.can_delete_photo
                    ? `<button class="mini danger" data-action="delete_photo" data-photo-id="${esc(
                        p.id
                    )}">削除</button>`
                    : ``
                }
        </div>
      </div>
    `
        )
        .join("");

    const zipUrl = a.zip_url ?? (API_URL + "?action=download_album_zip&album_id=" + a.id);

    return `
    <div class="card" data-album-id="${esc(a.id)}">
      <div class="card-head">
        <h3>${esc(a.title)}</h3>

        <div class="actions">
          ${a.can_edit
            ? `<button data-action="edit_album" data-album-id="${esc(a.id)}">編集</button>`
            : ``
        }

          ${a.can_edit
            ? `<button data-action="add_photos" data-album-id="${esc(a.id)}">写真追加</button>`
            : ``
        }

          <a class="btn" href="${esc(zipUrl)}">アルバムZIP</a>

          ${a.can_delete
            ? `<button class="danger" data-action="delete_album" data-album-id="${esc(
                a.id
            )}">アルバム削除</button>`
            : ``
        }
        </div>
      </div>

      <p class="desc">${esc(a.description).replaceAll("\n", "<br>")}</p>
      <small>作成：${esc(a.created_at)}</small>

      <div class="select-actions">
        <button class="mini" data-action="select_all" data-album-id="${esc(a.id)}">全選択</button>
        <button class="mini" data-action="select_none" data-album-id="${esc(a.id)}">全解除</button>
        <button class="mini" data-action="download_selected_zip" data-album-id="${esc(
            a.id
        )}">選択ZIP DL</button>
      </div>

      <div class="thumbs">${thumbs || `<p class="muted">写真なし</p>`}</div>
    </div>
  `;
}

function render(data) {
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

        const text = await res.text();
        // JSON以外が混ざってたらここで気づける
        // console.log("API raw:", text);

        const data = JSON.parse(text);
        render(data);
    } catch (e) {
        console.error(e);
        listEl.innerHTML = `<pre style="background:#111;color:#0f0;padding:12px;white-space:pre-wrap;">${esc(
            e?.stack || String(e)
        )}</pre>`;
    }
}

// --------------------
// 作成（複数写真）
// --------------------
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

// --------------------
// 一覧側ボタン（委譲）
// --------------------
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

    // アルバム編集（prompt）
    if (action === "edit_album") {
        const albumId = btn.dataset.albumId;

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

    // 写真追加
    if (action === "add_photos") {
        const albumId = btn.dataset.albumId;

        const input = document.createElement("input");
        input.type = "file";
        input.accept = "image/*";
        input.multiple = true;

        input.onchange = async () => {
            const fd = new FormData();
            fd.append("action", "add_photos");
            fd.append("album_id", albumId);
            [...input.files].forEach((f) => fd.append("images[]", f));

            const res = await fetch(API_URL, { method: "POST", body: fd });
            if (!res.ok) alert("写真追加に失敗しました");
            await load();
        };

        input.click();
        return;
    }

    // 全選択
    if (action === "select_all" || action === "select_none") {
        const card = btn.closest(".card");
        if (!card) return;
        const checks = [...card.querySelectorAll(".pick-photo")];
        const on = action === "select_all";
        checks.forEach((c) => (c.checked = on));
        return;
    }

    // 選択ZIP DL（POSTでZIPを返す → Blobで保存）
    if (action === "download_selected_zip") {
        const card = btn.closest(".card");
        if (!card) return;

        const ids = [...card.querySelectorAll(".pick-photo:checked")].map((c) => c.dataset.photoId);
        if (ids.length === 0) {
            alert("まず写真を選択して！");
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

        // ファイル名（アルバム名から）
        const title = card.querySelector("h3")?.textContent?.trim() || "selected_photos";
        a.download = `${title}_selected.zip`;

        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        return;
    }
});

load();
