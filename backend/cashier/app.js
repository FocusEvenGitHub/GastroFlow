document.getElementById('orderForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const data = new FormData(e.target);

    const body = {
        table: data.get('table'),
        items: data.get('items')
    };

    const res = await fetch('/api/orders', {
        method: 'POST',
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify(body)
    });

    document.getElementById('msg').textContent = await res.text();
    e.target.reset();
});
