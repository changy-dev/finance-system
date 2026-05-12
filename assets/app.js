let pieChart;

fetch('api/analytics.php')
.then(res=>res.json())
.then(data=>{

// SUMMARY
document.getElementById('income').innerText = "KES "+data.income;
document.getElementById('expense').innerText = "KES "+data.expense;
document.getElementById('balance').innerText = "KES "+data.balance;
document.getElementById('savingsRate').innerText = data.savingsRate+"%";

// LINE CHART
new Chart(document.getElementById('lineChart'),{
    type:'line',
    data:{
        labels:data.months,
        datasets:[{
            label:'Expenses',
            data:data.monthly,
            borderColor:'#60a5fa',
            fill:true,
            tension:0.4
        }]
    }
});

// PIE CHART
pieChart = new Chart(document.getElementById('pieChart'),{
    type:'doughnut',
    data:{
        labels:data.categories,
        datasets:[{
            data:data.categoryData,
            backgroundColor:[
                "#f43f5e","#8b5cf6","#22c55e","#3b82f6","#f59e0b"
            ]
        }]
    },
    options:{
        onHover:(e,el)=>{
            if(el.length){
                let i = el[0].index;
                document.getElementById('centerValue').innerText =
                    data.categories[i] + " - KES " + data.categoryData[i];
            }
        }
    }
});

// INSIGHTS
document.getElementById('insights').innerHTML =
`You spent <b>KES ${data.expense}</b> this month. 
Top category: <b>${data.topCategory}</b>`;

});