document.addEventListener('DOMContentLoaded', function () {
    if (typeof rarChartData === 'undefined') {
        return;
    }

    const lineCtx = document.getElementById('rarLineChart');
    if (lineCtx) {
        new Chart(lineCtx, {
            type: 'line',
            data: {
                labels: rarChartData.line.labels,
                datasets: [{
                    label: 'Clicks',
                    data: rarChartData.line.data,
                    borderColor: 'rgba(54, 162, 235, 1)',
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    title: { display: true, text: 'Clicks Over Time' }
                },
                scales: {
                    x: { title: { display: true, text: 'Date' } },
                    y: { beginAtZero: true, title: { display: true, text: 'Clicks' } }
                }
            }
        });
    }

    const doughnutCtx = document.getElementById('rarDoughnutChart');
    if (doughnutCtx) {
        new Chart(doughnutCtx, {
            type: 'doughnut',
            data: {
                labels: rarChartData.doughnut.labels,
                datasets: [{
                    label: 'Clicks',
                    data: rarChartData.doughnut.data,
                    backgroundColor: [
                        '#4dc9f6', '#f67019', '#f53794',
                        '#537bc4', '#acc236', '#166a8f',
                        '#00a950', '#58595b', '#8549ba'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }
});