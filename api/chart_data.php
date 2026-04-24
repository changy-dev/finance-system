<script>
let labels = <?php echo json_encode($catLabels); ?>;
let dataVals = <?php echo json_encode($catData); ?>;
let colors = <?php echo json_encode($catColors); ?>;

let visibleData = [...dataVals]; // track visible values

let total = dataVals.reduce((a,b)=>a+b,0);

/* ===== CENTER ANIMATION ===== */
function animateValue(start, end, duration, label="Total"){
    let startTime = null;

    function animation(currentTime){
        if(!startTime) startTime = currentTime;
        let progress = Math.min((currentTime - startTime) / duration, 1);

        let value = Math.floor(progress * (end - start) + start);

        document.getElementById('centerLabel').innerText = label;
        document.getElementById('centerValue').innerText =
            "KES " + value.toLocaleString();

        if(progress < 1){
            requestAnimationFrame(animation);
        }
    }

    requestAnimationFrame(animation);
}

/* INITIAL LOAD ANIMATION */
animateValue(0, total, 800);

/* ===== CHART ===== */
let pie = new Chart(document.getElementById('pieChart'),{
    type:'doughnut',
    data:{
        labels: labels,
        datasets:[{
            data: visibleData,
            backgroundColor: colors
        }]
    },
    options:{
        cutout:'70%',

        plugins:{
            legend:{
                display:true,
                onClick:(e, legendItem, legend)=>{
                    let index = legendItem.index;

                    // toggle visibility
                    if(visibleData[index] !== 0){
                        visibleData[index] = 0;
                    }else{
                        visibleData[index] = dataVals[index];
                    }

                    pie.data.datasets[0].data = visibleData;
                    pie.update();

                    // recalc total
                    let newTotal = visibleData.reduce((a,b)=>a+b,0);
                    animateValue(0, newTotal, 500);
                },
                labels:{
                    color:'#cbd5f5',
                    padding:15,
                    font:{ size:13, weight:'600' }
                }
            },

            tooltip:{
                callbacks:{
                    label:function(ctx){
                        let val = ctx.raw;
                        let total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                        let percent = total ? ((val/total)*100).toFixed(1) : 0;

                        return ctx.label + ": KES " +
                        val.toLocaleString() + " ("+percent+"%)";
                    }
                }
            }
        },

        onHover:(evt, elements)=>{
            if(elements.length){
                let i = elements[0].index;
                animateValue(0, visibleData[i], 300, labels[i]);
            }else{
                let newTotal = visibleData.reduce((a,b)=>a+b,0);
                animateValue(0, newTotal, 300, "Total");
            }
        }
    }
});
</script>