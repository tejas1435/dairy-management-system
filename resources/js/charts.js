import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    DoughnutController,
    ArcElement,
    PointElement,
    Tooltip,
} from 'chart.js';

// Only the controllers the dashboard actually uses are registered, which keeps
// the bundle smaller than importing Chart.js's auto-registering entry point.
Chart.register(
    BarController,
    BarElement,
    LineController,
    LineElement,
    DoughnutController,
    ArcElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Filler,
    Legend,
    Tooltip,
);

Chart.defaults.font.family =
    'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
Chart.defaults.font.size = 12;
Chart.defaults.maintainAspectRatio = false;
Chart.defaults.plugins.legend.position = 'bottom';

export { Chart };
