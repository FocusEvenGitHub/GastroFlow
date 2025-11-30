async function load() {
    const res = await fetch('/api/orders?status=pending');
    const orders = await res.json();
    const list = document.getElementById('list');

    if (!orders.length) {
        list.innerHTML = "<p>Nenhum pedido pendente.</p>";
        return;
    }

    list.innerHTML = "";

    orders.forEach(o => {
        const div = document.createElement('div');
        div.style.border = "1px solid #ccc";
        div.style.margin = "8px";
        div.style.padding = "8px";

        div.innerHTML = `
            <b>Pedido #${o.id} — Mesa ${o.table_number}</b>
            <p>${o.items}</p>
            <button onclick="complete(${o.id})">Dar baixa</button>
        `;

        list.appendChild(div);
    });
}

async function complete(id) {
    await fetch(`/api/orders/${id}/complete`, {method: 'POST'});
    load();
}

load();
setInterval(load, 4000);
