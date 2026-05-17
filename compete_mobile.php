<?php
// compete_mobile.php - 竞对数据手机端查看页
session_start();
header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['agent_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>查看竞对</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; }
        .card-shadow { box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05); }
        .glass-nav {
            background: #6d28d9;
            border-top: 1px solid #5b21b6;
            padding-bottom: env(safe-area-inset-bottom);
            box-shadow: 0 -6px 24px rgba(91, 33, 182, 0.38);
        }
        .glass-nav-item {
            color: rgba(255, 255, 255, 0.78);
            transition: color 0.15s, background 0.15s;
            padding: 6px 10px;
            border-radius: 12px;
        }
        .glass-nav-item-active {
            color: #ffffff;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.22);
            padding: 6px 10px;
            border-radius: 12px;
        }
        .summary-table th, .summary-table td {
            padding: 8px 6px;
            text-align: center;
            font-size: 12px;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }
        .summary-table th {
            color: #64748b;
            font-weight: 700;
            background: #f8fafc;
        }
        .summary-table td {
            color: #334155;
            background: #ffffff;
        }
        .summary-table .summary-total td {
            font-weight: 700;
            background: #f8fafc;
        }
    </style>
</head>
<body>
<div id="app" class="max-w-md mx-auto min-h-screen bg-gray-50 pb-24">
    <div class="sticky top-0 z-40 bg-white/95 backdrop-blur p-4 shadow-sm flex items-center justify-between">
        <button type="button" @click="goBack" class="text-slate-500 text-sm px-2 py-1 rounded hover:bg-slate-100">
            <i class="fas fa-chevron-left mr-1"></i> 返回
        </button>
        <h2 class="font-bold text-slate-800">查看竞对</h2>
        <a href="compete.php" class="text-xs bg-purple-600 text-white px-3 py-1.5 rounded-lg font-bold">
            录入竞对
        </a>
    </div>

    <div class="p-4 space-y-4">
        <div class="bg-white rounded-2xl p-4 card-shadow">
            <div class="text-xs font-bold text-slate-500 mb-2">项目（可多选）</div>
            <div class="relative">
                <button type="button" @click="showProjectDropdown = !showProjectDropdown" class="w-full bg-slate-50 border border-gray-200 rounded-lg p-2 text-sm text-left flex items-center justify-between">
                    <span class="truncate text-slate-700">{{ selectedProjectsText || '请选择项目' }}</span>
                    <i class="fas fa-chevron-down text-gray-400 transition-transform" :class="showProjectDropdown ? 'rotate-180' : ''"></i>
                </button>
                <div v-if="showProjectDropdown" class="absolute z-30 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg overflow-hidden">
                    <div class="px-3 py-2 border-b border-gray-100 flex items-center justify-between">
                        <label class="text-xs text-slate-600 flex items-center gap-2">
                            <input type="checkbox" :checked="selectAllProjects" @change="toggleSelectAllProjects" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                            全选
                        </label>
                        <button type="button" @click="showProjectDropdown=false" class="text-[11px] text-gray-400">收起</button>
                    </div>
                    <div class="max-h-48 overflow-y-auto p-1">
                        <label v-for="p in projects" :key="p.id" class="flex items-center gap-2 px-2 py-2 rounded hover:bg-slate-50 text-sm text-slate-700">
                            <input type="checkbox" :checked="isProjectSelected(p.id)" @change="toggleProjectSelection(p.id)" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                            <span class="truncate">{{ p.name }}</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="mt-1 text-[11px] text-gray-400">不选择表示全部项目</div>

            <div class="grid grid-cols-2 gap-3 mt-3">
                <div>
                    <div class="text-xs font-bold text-slate-500 mb-1">开始日期</div>
                    <input v-model="filters.date_start" type="date" class="w-full bg-slate-50 border border-gray-200 rounded-lg p-2 text-sm outline-none">
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-500 mb-1">结束日期</div>
                    <input v-model="filters.date_end" type="date" class="w-full bg-slate-50 border border-gray-200 rounded-lg p-2 text-sm outline-none">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 mt-3">
                <button type="button" @click="resetFilters" class="bg-gray-100 text-gray-600 py-2.5 rounded-lg text-sm font-bold">重置</button>
                <button type="button" @click="loadData" class="bg-purple-600 text-white py-2.5 rounded-lg text-sm font-bold">筛选</button>
            </div>
        </div>

        <div v-if="summaryRows.length > 0" class="bg-white rounded-2xl card-shadow overflow-hidden">
            <div class="px-4 pt-4 pb-2">
                <div class="font-bold text-slate-800 text-sm">竞争对手数据汇总</div>
            </div>
            <div class="overflow-x-auto">
                <table class="summary-table min-w-full">
                    <thead>
                        <tr>
                            <th>
                                <button type="button" @click="toggleSort('project_name')" class="inline-flex items-center gap-1">
                                    项目
                                    <i v-if="sortKey==='project_name'" class="fas text-[10px]" :class="sortOrder==='asc' ? 'fa-sort-up' : 'fa-sort-down'"></i>
                                </button>
                            </th>
                            <th>平台</th>
                            <th>
                                <button type="button" @click="toggleSort('visits')" class="inline-flex items-center gap-1">
                                    带看数
                                    <i v-if="sortKey==='visits'" class="fas text-[10px]" :class="sortOrder==='asc' ? 'fa-sort-up' : 'fa-sort-down'"></i>
                                </button>
                            </th>
                            <th>
                                <button type="button" @click="toggleSort('deals')" class="inline-flex items-center gap-1">
                                    成交数
                                    <i v-if="sortKey==='deals'" class="fas text-[10px]" :class="sortOrder==='asc' ? 'fa-sort-up' : 'fa-sort-down'"></i>
                                </button>
                            </th>
                            <th>
                                <button type="button" @click="toggleSort('locks')" class="inline-flex items-center gap-1">
                                    锁筹数
                                    <i v-if="sortKey==='locks'" class="fas text-[10px]" :class="sortOrder==='asc' ? 'fa-sort-up' : 'fa-sort-down'"></i>
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="summary-total">
                            <td>总计</td>
                            <td>---</td>
                            <td>{{ totalSummary.visits }}</td>
                            <td>{{ totalSummary.deals }}</td>
                            <td>{{ totalSummary.locks }}</td>
                        </tr>
                        <template v-for="row in sortedSummaryRows" :key="row.project_name">
                            <tr>
                                <td>
                                    <button
                                        type="button"
                                        @click="toggleDetailRow(row.project_name)"
                                        class="font-medium text-indigo-700 underline underline-offset-2 decoration-dotted"
                                    >
                                        {{ row.project_name }}
                                    </button>
                                </td>
                                <td>汇总</td>
                                <td>{{ row.visits }}</td>
                                <td>{{ row.deals }}</td>
                                <td>{{ row.locks }}</td>
                            </tr>
                            <tr v-if="expandedProjectName === row.project_name">
                                <td colspan="5" class="!p-0">
                                    <div class="bg-slate-50 px-3 py-2 border-b border-gray-200">
                                        <div class="divide-y divide-gray-200 rounded-lg bg-white border border-gray-200 overflow-hidden">
                                            <div v-for="item in getProjectItems(row.project_name)" :key="item.platform_name" class="p-2.5">
                                                <div class="w-full overflow-x-auto">
                                                    <div class="w-full flex items-center justify-between gap-2 text-[11px] leading-6 whitespace-nowrap">
                                                        <div class="text-slate-700"><span class="text-gray-500">平台:</span> <span class="font-bold">{{ item.platform_name || '-' }}</span></div>
                                                        <div class="text-slate-700"><span class="text-gray-500">统计条数:</span> <span class="font-bold">{{ item.row_count || 0 }}</span></div>
                                                        <div class="text-slate-700"><span class="text-gray-500">来访:</span> <span class="font-bold text-blue-600">{{ item.visits || 0 }}</span></div>
                                                        <div class="text-slate-700"><span class="text-gray-500">成交:</span> <span class="font-bold text-green-600">{{ item.deals || 0 }}</span></div>
                                                        <div class="text-slate-700"><span class="text-gray-500">锁筹:</span> <span class="font-bold text-purple-600">{{ item.locks || 0 }}</span></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="loading" class="text-center text-sm text-gray-500 py-8">加载中...</div>
        <div v-else-if="groupedList.length===0" class="bg-white rounded-2xl p-8 text-center text-sm text-gray-400 card-shadow">暂无竞对数据</div>

    </div>

    <div class="fixed bottom-0 w-full max-w-md flex justify-around py-3 pt-2.5 text-[10px] z-40 glass-nav">
        <a href="compete_mobile.php" class="glass-nav-item-active flex flex-col items-center gap-1 cursor-pointer min-w-[56px]"><i class="fas fa-chart-line text-lg"></i><span>数据</span></a>
        <a href="agent.php" class="glass-nav-item flex flex-col items-center gap-1 cursor-pointer min-w-[56px]"><i class="fas fa-file-alt text-lg"></i><span>报备</span></a>
        <a href="staff.php" class="glass-nav-item flex flex-col items-center gap-1 cursor-pointer min-w-[56px]"><i class="fas fa-check-double text-lg"></i><span>工作台</span></a>
        <a href="staff.php?tab=list" class="glass-nav-item flex flex-col items-center gap-1 cursor-pointer min-w-[56px]"><i class="fas fa-clock text-lg"></i><span>记录</span></a>
    </div>
</div>

<script>
const { createApp, ref, computed, onMounted } = Vue;
createApp({
    setup() {
        const projects = ref([]);
        const rows = ref([]);
        const loading = ref(false);
        const expandedProjectName = ref('');
        const filters = ref({
            project_ids: [],
            date_start: '',
            date_end: ''
        });

        const showProjectDropdown = ref(false);
        const sortKey = ref('project_name');
        const sortOrder = ref('asc');

        const groupedList = computed(() => {
            const projectMap = {};
            rows.value.forEach((r) => {
                const projectKey = r.project_name || '未命名项目';
                if (!projectMap[projectKey]) projectMap[projectKey] = {};
                const platformKey = (r.platform_name && String(r.platform_name).trim()) ? String(r.platform_name).trim() : '未设置平台';
                if (!projectMap[projectKey][platformKey]) {
                    projectMap[projectKey][platformKey] = {
                        platform_name: platformKey,
                        visits: 0,
                        deals: 0,
                        locks: 0,
                        row_count: 0
                    };
                }
                projectMap[projectKey][platformKey].visits += parseInt(r.visits, 10) || 0;
                projectMap[projectKey][platformKey].deals += parseInt(r.deals, 10) || 0;
                projectMap[projectKey][platformKey].locks += parseInt(r.locks, 10) || 0;
                projectMap[projectKey][platformKey].row_count += 1;
            });
            return Object.keys(projectMap).map((projectName) => {
                const items = Object.values(projectMap[projectName]).sort((a, b) => String(a.platform_name).localeCompare(String(b.platform_name)));
                return {
                    project_name: projectName,
                    items
                };
            });
        });

        const summaryRows = computed(() => {
            return groupedList.value.map((group) => {
                const itemSum = group.items.reduce((acc, item) => {
                    acc.visits += parseInt(item.visits, 10) || 0;
                    acc.deals += parseInt(item.deals, 10) || 0;
                    acc.locks += parseInt(item.locks, 10) || 0;
                    return acc;
                }, { visits: 0, deals: 0, locks: 0 });
                return {
                    project_name: group.project_name,
                    visits: itemSum.visits,
                    deals: itemSum.deals,
                    locks: itemSum.locks
                };
            });
        });

        const totalSummary = computed(() => {
            return summaryRows.value.reduce((acc, row) => {
                acc.visits += parseInt(row.visits, 10) || 0;
                acc.deals += parseInt(row.deals, 10) || 0;
                acc.locks += parseInt(row.locks, 10) || 0;
                return acc;
            }, { visits: 0, deals: 0, locks: 0 });
        });
        const sortedSummaryRows = computed(() => {
            const list = [...summaryRows.value];
            list.sort((a, b) => {
                let va = a[sortKey.value];
                let vb = b[sortKey.value];
                if (sortKey.value === 'project_name') {
                    va = String(va || '');
                    vb = String(vb || '');
                    return sortOrder.value === 'asc'
                        ? va.localeCompare(vb, 'zh-Hans-CN')
                        : vb.localeCompare(va, 'zh-Hans-CN');
                }
                va = Number(va) || 0;
                vb = Number(vb) || 0;
                return sortOrder.value === 'asc' ? va - vb : vb - va;
            });
            return list;
        });
        const toggleSort = (key) => {
            if (sortKey.value === key) {
                sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc';
                return;
            }
            sortKey.value = key;
            sortOrder.value = key === 'project_name' ? 'asc' : 'desc';
        };

        const selectAllProjects = computed(() => {
            return projects.value.length > 0 && (filters.value.project_ids || []).length === projects.value.length;
        });

        const selectedProjectsText = computed(() => {
            const selected = (filters.value.project_ids || []).map(String);
            if (!selected.length) return '';
            if (selected.length === projects.value.length) return '全部项目';
            const names = projects.value
                .filter((p) => selected.includes(String(p.id)))
                .map((p) => p.name);
            if (names.length <= 2) return names.join('、');
            return names.slice(0, 2).join('、') + ` 等${names.length}项`;
        });

        const isProjectSelected = (id) => (filters.value.project_ids || []).map(String).includes(String(id));
        const toggleProjectSelection = (id) => {
            const sid = String(id);
            const next = (filters.value.project_ids || []).map(String);
            const idx = next.indexOf(sid);
            if (idx >= 0) next.splice(idx, 1);
            else next.push(sid);
            filters.value.project_ids = next;
        };
        const toggleSelectAllProjects = () => {
            if (selectAllProjects.value) filters.value.project_ids = [];
            else filters.value.project_ids = projects.value.map((p) => String(p.id));
        };

        const getProjectItems = (projectName) => {
            const target = groupedList.value.find((g) => g.project_name === projectName);
            return target ? (target.items || []) : [];
        };
        const toggleDetailRow = (projectName) => {
            expandedProjectName.value = expandedProjectName.value === projectName ? '' : projectName;
        };

        const loadProjects = async () => {
            const r = await fetch('admin/compete_list.php?action=get_projects');
            const d = await r.json();
            projects.value = Array.isArray(d) ? d : [];
        };

        const loadData = async () => {
            loading.value = true;
            try {
                const u = new URL('admin/compete_list.php', window.location.href);
                u.searchParams.set('action', 'get_compete_list');
                u.searchParams.set('project_ids', (filters.value.project_ids || []).join(','));
                u.searchParams.set('date_start', filters.value.date_start || '');
                u.searchParams.set('date_end', filters.value.date_end || '');
                const r = await fetch(u.toString());
                const d = await r.json();
                rows.value = Array.isArray(d) ? d : [];
                if (expandedProjectName.value && !groupedList.value.some((g) => g.project_name === expandedProjectName.value)) {
                    expandedProjectName.value = '';
                }
            } catch (e) {
                rows.value = [];
                alert('加载失败，请稍后重试');
            } finally {
                loading.value = false;
            }
        };

        const resetFilters = () => {
            filters.value = { project_ids: [], date_start: '', date_end: '' };
            showProjectDropdown.value = false;
            loadData();
        };

        const goBack = () => {
            if (window.history.length > 1) window.history.back();
            else window.location.href = 'staff.php';
        };

        onMounted(async () => {
            await loadProjects();
            await loadData();
        });

        return {
            projects, rows, loading, filters, groupedList, summaryRows, sortedSummaryRows, totalSummary,
            sortKey, sortOrder, toggleSort,
            expandedProjectName, getProjectItems, toggleDetailRow,
            showProjectDropdown, selectAllProjects, selectedProjectsText, isProjectSelected, toggleProjectSelection, toggleSelectAllProjects,
            loadData, resetFilters, goBack
        };
    }
}).mount('#app');
</script>
</body>
</html>
