// Setup module
// ------------------------------


// Initialize module
// ------------------------------
var genderPieGraphOptions = {
    title: {
        text: 'Total Interns'
    },
    series: totalGender['total'],
    chart: {
        width: 450,
        type: 'pie',
    },
    labels: totalGender['gender'],
    legend: {
        show: false
    },
    tooltip: {
        y: {
            formatter: function (value) {
                return value + " Invoice";
            }
        }
    },
    responsive: [{
        breakpoint: 480,
        options: {
            chart: {
                width: 200
            },
            legend: {
                position: 'bottom'
            }
        }
    }]
};
var genderPieGraphChart = new ApexCharts(document.querySelector("#genderPieGraph"), genderPieGraphOptions);

document.addEventListener('DOMContentLoaded', function() {
    genderPieGraphChart.render();

});
