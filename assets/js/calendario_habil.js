// Misma regla que config/festivos.php. UTC evita saltos por zona horaria/DST.
function sagaEsFestivo(fecha) {
    const year = Number(String(fecha).slice(0, 4));
    if (!Number.isInteger(year) || year < 1583 || year > 9999) return false;
    const floor = Math.floor;
    const a=year%19, b=floor(year/100), c=year%100;
    const d=floor(b/4), e=b%4, f=floor((b+8)/25), g=floor((b-f+1)/3);
    const h=(19*a+b-d-g+15)%30, i=floor(c/4), k=c%4;
    const l=(32+2*e+2*i-h-k)%7, m=floor((a+11*h+22*l)/451), v=h+l-7*m+114;
    const easter = new Date(Date.UTC(year, floor(v/31)-1, v%31+1));
    const iso = date => date.toISOString().slice(0,10);
    const days = ['01-01','05-01','07-20','08-07','12-08','12-25'].map(md => `${year}-${md}`);
    for (const md of ['01-06','03-19','06-29','08-15','10-12','11-01','11-11']) {
        const date = new Date(`${year}-${md}T00:00:00Z`);
        date.setUTCDate(date.getUTCDate()+(8-date.getUTCDay())%7);
        days.push(iso(date));
    }
    for (const delta of [-3,-2,43,64,71]) {
        const date = new Date(easter);
        date.setUTCDate(date.getUTCDate()+delta);
        days.push(iso(date));
    }
    return days.includes(fecha);
}
