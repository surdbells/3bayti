/**
 * ApexCharts theming factory.
 *
 * Pages that already use ng-apexcharts keep all their existing chart code
 * unchanged — they just wrap their options in `themedChart({...})` and they
 * inherit the brand palette, typography, and tooltip styling automatically.
 *
 *   chartOptions: ApexOptions = themedChart({
 *     series: [ { name: 'Revenue', data: [...] } ],
 *     chart: { type: 'area', height: 300 },
 *     xaxis: { categories: monthLabels },
 *   });
 *
 * Deep-merges the user options over the theme defaults so anything the
 * caller sets wins.
 */

export interface AxChartThemeOptions {
  /** Light or dark mode. Default follows --ax-theme-mode or 'light'. */
  mode?: 'light' | 'dark';
  /** Additional colours to append after the default brand palette. */
  extraColors?: string[];
}

/** The brand colour palette used in chart series. Ordered by perceptual
 *  distinctness so the first N colours always look good without tuning. */
export const AX_CHART_PALETTE = [
  '#906952', // espresso (primary brand brown)
  '#c9a227', // gold
  '#2e5f4b', // deep teal
  '#8b3a2e', // brick
  '#4a6b8a', // muted blue
  '#7a5844', // caramel
  '#5b8fa0', // steel
  '#b68e75', // tan
] as const;

/** Returns a themed ApexCharts option set. The `source` is merged over the
 *  base theme; caller settings always win. */
export function themedChart<T extends Record<string, any>>(
  source: T,
  options: AxChartThemeOptions = {},
): T {
  const mode = options.mode ?? 'light';
  const colors = [...AX_CHART_PALETTE, ...(options.extraColors ?? [])];

  const base: Record<string, any> = {
    chart: {
      fontFamily: "'Inter', system-ui, -apple-system, sans-serif",
      background: 'transparent',
      toolbar: { show: false },
      zoom: { enabled: false },
      animations: {
        enabled: true,
        speed: 350,
        animateGradually: { enabled: false },
        dynamicAnimation: { enabled: true, speed: 350 },
      },
    },
    colors,
    theme: { mode },
    grid: {
      borderColor: mode === 'dark' ? 'rgba(255,255,255,0.08)' : 'rgba(28,25,22,0.08)',
      strokeDashArray: 3,
      padding: { top: 0, right: 10, bottom: 0, left: 10 },
    },
    dataLabels: { enabled: false },
    legend: {
      fontSize: '12px',
      fontFamily: "'Inter', system-ui, sans-serif",
      fontWeight: 500,
      labels: { colors: mode === 'dark' ? '#d8d3c9' : '#5a554a' },
      markers: { width: 10, height: 10, radius: 5 },
      itemMargin: { horizontal: 10, vertical: 4 },
    },
    xaxis: {
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: {
        style: {
          colors: mode === 'dark' ? '#a8a092' : '#7d7669',
          fontSize: '11px',
          fontFamily: "'Inter', system-ui, sans-serif",
        },
      },
    },
    yaxis: {
      labels: {
        style: {
          colors: mode === 'dark' ? '#a8a092' : '#7d7669',
          fontSize: '11px',
          fontFamily: "'Inter', system-ui, sans-serif",
        },
      },
    },
    tooltip: {
      theme: mode,
      style: {
        fontSize: '12px',
        fontFamily: "'Inter', system-ui, sans-serif",
      },
      marker: { show: true },
    },
    stroke: {
      curve: 'smooth',
      width: 2,
    },
    fill: {
      type: 'gradient',
      gradient: {
        shadeIntensity: 1,
        opacityFrom: 0.4,
        opacityTo: 0.05,
        stops: [0, 100],
      },
    },
    plotOptions: {
      bar: {
        borderRadius: 4,
        columnWidth: '55%',
        dataLabels: { position: 'top' },
      },
      pie: {
        donut: {
          size: '72%',
          labels: {
            show: true,
            name: {
              fontSize: '14px',
              fontFamily: "'Fraunces', serif",
              fontWeight: 600,
              color: mode === 'dark' ? '#f5f1eb' : '#1c1916',
            },
            value: {
              fontSize: '20px',
              fontFamily: "'Fraunces', serif",
              fontWeight: 600,
              color: mode === 'dark' ? '#f5f1eb' : '#1c1916',
            },
            total: {
              show: true,
              label: 'Total',
              fontSize: '12px',
              fontFamily: "'Inter', sans-serif",
              fontWeight: 500,
              color: mode === 'dark' ? '#a8a092' : '#7d7669',
            },
          },
        },
      },
    },
  };

  return deepMerge(base, source) as T;
}

/** Recursively merge source into target. Arrays are replaced (not concatenated). */
function deepMerge(target: any, source: any): any {
  if (isPlainObject(target) && isPlainObject(source)) {
    const out: Record<string, any> = { ...target };
    for (const key of Object.keys(source)) {
      if (isPlainObject(target[key]) && isPlainObject(source[key])) {
        out[key] = deepMerge(target[key], source[key]);
      } else {
        out[key] = source[key];
      }
    }
    return out;
  }
  return source ?? target;
}

function isPlainObject(v: unknown): v is Record<string, any> {
  return typeof v === 'object' && v !== null && !Array.isArray(v) && Object.getPrototypeOf(v) === Object.prototype;
}
