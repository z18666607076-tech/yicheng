<?php
// admin_companies.php - 商户/中介独立管理页 (v2.0: 分页+高级筛选)
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 登录验证 ===
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>商户资源管理</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .glass-nav { background: #0f172a; color: #94a3b8; }
        .nav-item.active { background: #2563eb; color: white; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.5); }
        /* 分页按钮样式 */
        .page-btn { @apply px-3 py-1 border rounded bg-white text-gray-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition text-sm; }
        .page-btn.active { @apply bg-blue-600 text-white border-blue-600; }
        .filter-input { @apply border rounded-lg px-3 py-2 text-sm w-full outline-none focus:ring-2 focus:ring-blue-100 transition; }
        .companies-table { border-collapse: separate; border-spacing: 0; font-size: 11px; }
        .companies-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            box-shadow: inset 0 -1px 0 #e2e8f0;
        }
        .companies-table tbody tr:hover { background: #faf5ff; }
        .cell-clamp-3 {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
    </style>
</head>
<body>
<div id="app" class="flex h-screen overflow-hidden">
    
    <?php include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden bg-slate-50">
        <header class="bg-white border-b border-gray-200 shadow-sm flex-shrink-0 z-10 px-4 md:px-8 py-2 md:py-3 flex flex-col gap-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-4 min-w-0">
                    <button type="button" @click="sidebarOpen = !sidebarOpen" class="md:hidden shrink-0 text-gray-500"><i class="fas fa-bars"></i></button>
                    <h2 class="text-lg font-bold text-slate-800 truncate">商户资源管理</h2>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <button type="button" @click="loadData" class="text-gray-500 hover:text-blue-600 transition" title="刷新数据"><i class="fas fa-sync-alt" :class="{'animate-spin': loading}"></i></button>
                    <button type="button" @click="openEdit()" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 transition shadow-lg flex items-center gap-2">
                        <i class="fas fa-plus"></i> 新增商户
                    </button>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-6 flex flex-col">
            
            <div class="bg-white p-4 rounded-xl shadow-sm mb-4 border border-gray-100">
                <div v-if="selectedCompanies.length > 0" class="flex items-center gap-3 mb-3 p-2 bg-blue-50 rounded-lg border border-blue-100">
                    <span class="text-xs font-bold text-blue-700">已选择 {{ selectedCompanies.length }} 个商户</span>
                    <button type="button" @click="openBatchEditModal" class="bg-blue-600 text-white text-xs px-3 py-1 rounded hover:bg-blue-700 transition flex items-center gap-1">
                        <i class="fas fa-users-cog"></i> 批量修改渠道负责人
                    </button>
                    <button type="button" @click="selectedCompanies = []" class="text-blue-600 text-xs px-3 py-1 rounded hover:bg-blue-100 transition">
                        取消选择
                    </button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
                    <div class="md:col-span-2">
                        <label class="text-[10px] font-bold text-gray-400 mb-1 block uppercase">关键词搜索</label>
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                            <input v-model="filters.kw" @keyup.enter="handleSearch" class="pl-9 filter-input" placeholder="公司名 / 门店 / 姓名 / 电话">
                        </div>
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-gray-400 mb-1 block uppercase">板块 (Region)</label>
                        <input v-model="filters.plate" @keyup.enter="handleSearch" class="filter-input" placeholder="如: 陈江">
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-gray-400 mb-1 block uppercase">地区 (Area)</label>
                        <input v-model="filters.area" @keyup.enter="handleSearch" class="filter-input" placeholder="如: 五一片区">
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-gray-400 mb-1 block uppercase">加盟品牌</label>
                        <select v-model="filters.franchise_brand" class="filter-input" @change="handleSearch">
                            <option value="">全部品牌</option>
                            <option v-for="b in franchiseBrands" :key="b" :value="b">{{ b }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-gray-400 mb-1 block uppercase">渠道负责人</label>
                        <div class="flex gap-2">
                            <input v-model="filters.follower" @keyup.enter="handleSearch" class="filter-input" placeholder="姓名">
                            <button type="button" @click="handleSearch" class="bg-blue-600 text-white px-4 rounded-lg hover:bg-blue-700 transition"><i class="fas fa-arrow-right"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto min-h-0 pr-1">
                <div v-if="!loading && companies.length === 0" class="text-center py-20 bg-white rounded-xl border border-dashed border-gray-200">
                    <div class="text-gray-300 text-4xl mb-3"><i class="fas fa-inbox"></i></div>
                    <p class="text-gray-400 text-sm">暂无数据，请尝试调整搜索条件</p>
                </div>

                <div v-else-if="loading && companies.length === 0" class="text-center py-16 bg-white rounded-xl border border-slate-100 text-slate-500 text-sm">
                    <i class="fas fa-circle-notch fa-spin mr-2"></i>加载中…
                </div>

                <div v-else-if="companies.length > 0" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="companies-table min-w-[1240px] w-full text-left text-slate-700">
                            <thead>
                                <tr class="text-[10px] sm:text-xs font-bold text-slate-600 whitespace-nowrap">
                                    <th class="px-2 py-3 w-10 text-center align-middle">
                                        <input
                                            ref="headerSelectAllRef"
                                            type="checkbox"
                                            class="rounded text-blue-600 focus:ring-blue-500 w-4 h-4 align-middle cursor-pointer"
                                            title="全选本页"
                                            :checked="allPageRowsSelected"
                                            @change="onToggleSelectAllPage"
                                        >
                                    </th>
                                    <th class="px-2 py-3 align-middle">渠道负责人</th>
                                    <th class="px-2 py-3 align-middle min-w-[8rem]">公司名称</th>
                                    <th class="px-2 py-3 align-middle">所在片区</th>
                                    <th class="px-2 py-3 align-middle min-w-[10rem]">地址</th>
                                    <th class="px-2 py-3 align-middle">加盟</th>
                                    <th class="px-2 py-3 align-middle min-w-[7rem]" title="门店类型 + 经营状态（同一列上下显示）">门店/状态</th>
                                    <th class="px-2 py-3 align-middle min-w-[6rem]">关联门店</th>
                                    <th class="px-2 py-3 align-middle">联系人</th>
                                    <th class="px-2 py-3 align-middle">联系号码</th>
                                    <th class="px-2 py-3 align-middle">公司简称</th>
                                    <th class="px-2 py-3 align-middle">跟进历史</th>
                                    <th class="px-3 py-3 text-right align-middle">操作</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="c in companies" :key="c.id" class="align-top">
                                    <td class="px-2 py-2.5 text-center">
                                        <input type="checkbox" v-model="selectedCompanies" :value="c.id" class="rounded text-blue-600 focus:ring-blue-500 w-4 h-4" :title="'选择 #' + c.id">
                                    </td>
                                    <td class="px-2 py-2.5 font-bold text-slate-800">{{ c.follower || '公池' }}</td>
                                    <td class="px-2 py-2.5 min-w-0">
                                        <div class="font-bold text-slate-900 leading-snug break-words">{{ c.name }}</div>
                                    </td>
                                    <td class="px-2 py-2.5">
                                        <span v-if="c.region_main" class="text-blue-700 font-semibold">{{ c.region_main }}</span>
                                        <span v-if="c.region_main && c.region_sub" class="text-gray-400"> / </span>
                                        <span v-if="c.region_sub" class="text-slate-600">{{ c.region_sub }}</span>
                                        <span v-if="!c.region_main && !c.region_sub" class="text-gray-400">—</span>
                                    </td>
                                    <td class="px-2 py-2.5 text-slate-600 max-w-[14rem]">
                                        <p class="leading-snug break-words cell-clamp-3" :title="c.address">{{ c.address || '—' }}</p>
                                    </td>
                                    <td class="px-2 py-2.5 text-purple-800 break-words">{{ c.franchise_brand || '—' }}</td>
                                    <td class="px-2 py-2.5 text-slate-700 max-w-[10rem]">
                                        <div class="flex flex-col gap-1 items-start">
                                            <span v-if="c.store_type && String(c.store_type).trim()" class="text-[10px] text-orange-800 bg-orange-50 border border-orange-100 rounded px-1.5 py-0.5 font-semibold whitespace-nowrap">{{ c.store_type }}</span>
                                            <p v-if="c.business_status && String(c.business_status).trim()" class="font-mono text-[11px] sm:text-xs break-all leading-snug" :title="c.business_status">{{ c.business_status }}</p>
                                            <span v-if="!(c.store_type && String(c.store_type).trim()) && !(c.business_status && String(c.business_status).trim())" class="text-gray-400">—</span>
                                        </div>
                                    </td>
                                    <td class="px-2 py-2.5 text-slate-600 max-w-[10rem]">
                                        <p class="leading-snug break-words cell-clamp-3" :title="c.related_store || ''">{{ (c.related_store && String(c.related_store).trim()) || '—' }}</p>
                                    </td>
                                    <td class="px-2 py-2.5 font-semibold text-slate-800 break-words">{{ c.contact_name || '—' }}</td>
                                    <td class="px-2 py-2.5">
                                        <a v-if="c.contact_phone" :href="'tel:' + c.contact_phone" class="font-mono text-blue-600 hover:underline break-all">{{ c.contact_phone }}</a>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                    <td class="px-2 py-2.5 text-slate-700 break-words">{{ c.store_name || '—' }}</td>
                                    <td class="px-2 py-2.5">
                                        <a :href="'admin_filings.php?company_kw=' + encodeURIComponent(c.name || '')" target="_blank" rel="noopener" class="text-blue-600 hover:underline font-semibold inline-flex items-center gap-0.5 whitespace-nowrap">
                                            查看 <i class="fas fa-external-link-alt text-[9px]"></i>
                                        </a>
                                    </td>
                                    <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                        <div class="flex flex-col gap-1 items-end">
                                            <button type="button" @click="openEdit(c)" class="text-blue-600 text-xs font-bold py-1 px-2 rounded border border-blue-100 bg-blue-50 hover:bg-blue-100">编辑</button>
                                            <button type="button" @click="delComp(c.id)" class="text-red-600 text-xs font-bold py-1 px-2 rounded border border-red-100 bg-red-50 hover:bg-red-100">删除</button>
                                            <span class="text-[10px] text-gray-400 font-mono">ID {{ c.id }}</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="bg-white border-t border-gray-200 p-3 flex flex-col sm:flex-row flex-wrap justify-between items-stretch sm:items-center gap-3 mt-2 rounded-xl shadow-lg z-20">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-gray-500 min-w-0 flex-1">
                    <span class="shrink-0">
                        共 <span class="font-bold text-slate-700">{{ pagination.total }}</span> 条数据，
                        当前第 <span class="font-bold text-blue-600">{{ pagination.page }}</span> / {{ totalPages }} 页
                    </span>
                    <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                        <span class="text-gray-600 whitespace-nowrap">跳转到</span>
                        <input
                            v-model="jumpPageInput"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            maxlength="8"
                            class="w-14 px-2 py-1 border border-gray-300 rounded bg-white text-sm text-center tabular-nums text-slate-800 outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-500"
                            placeholder="页码"
                            aria-label="页码"
                            @keyup.enter="jumpToPage"
                        />
                        <span class="text-gray-500 whitespace-nowrap">页</span>
                        <button type="button" @click="jumpToPage" class="px-2.5 py-1 rounded border border-blue-200 bg-blue-50 text-blue-700 text-xs font-semibold hover:bg-blue-100 transition">
                            跳转
                        </button>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <select v-model.number="pagination.limit" @change="handleSearch" class="border rounded px-2 py-1 text-xs outline-none bg-gray-50">
                        <option :value="10">10条/页</option>
                        <option :value="20">20条/页</option>
                        <option :value="50">50条/页</option>
                        <option :value="100">100条/页</option>
                    </select>
                    <div class="flex flex-wrap items-center gap-1">
                        <button type="button" @click="changePage(1)" :disabled="pagination.page===1" class="page-btn">首页</button>
                        <button type="button" @click="changePage(pagination.page-1)" :disabled="pagination.page===1" class="page-btn">上一页</button>
                        <div class="flex flex-wrap items-center gap-0.5 px-1">
                            <template v-for="(item, idx) in paginationItems" :key="'pg-' + idx + '-' + (item.type === 'page' ? item.n : 'e')">
                                <span v-if="item.type === 'dots'" class="px-0.5 text-gray-400 text-sm select-none">…</span>
                                <button
                                    v-else
                                    type="button"
                                    @click="changePage(item.n)"
                                    class="min-w-[2rem] h-8 px-1.5 rounded border text-xs font-semibold tabular-nums transition"
                                    :class="pagination.page === item.n ? 'bg-blue-600 text-white border-blue-600 shadow-sm' : 'bg-white text-gray-600 border-gray-200 hover:bg-blue-50 hover:border-blue-200'"
                                >{{ item.n }}</button>
                            </template>
                        </div>
                        <button type="button" @click="changePage(pagination.page+1)" :disabled="pagination.page>=totalPages" class="page-btn">下一页</button>
                        <button type="button" @click="changePage(totalPages)" :disabled="pagination.page>=totalPages" class="page-btn">末页</button>
                    </div>
                </div>
            </div>

        </div>

        <!-- 批量修改跟进人模态框 -->
        <div v-if="showBatchEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-xl p-6 w-full max-w-md shadow-xl">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-lg text-slate-800">批量修改渠道负责人</h3>
                    <button @click="showBatchEditModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="space-y-4">
                    <div>
                        <label class="text-sm font-bold text-gray-600 mb-2 block">渠道负责人</label>
                        <select v-model="batchFollower" class="filter-input" @click="agents.length === 0 && loadAgents()">
                            <option value="">请选择渠道负责人</option>
                            <option v-for="agent in agents" :key="agent" :value="agent">{{ agent }}</option>
                        </select>
                    </div>
                    <div class="text-sm text-gray-500">
                        本次将修改 <span class="font-bold text-blue-600">{{ selectedCompanies.length }}</span> 个商户的渠道负责人
                    </div>
                </div>
                <div class="flex gap-3 mt-6">
                    <button @click="showBatchEditModal = false" class="flex-1 bg-gray-100 text-gray-700 py-2 rounded-lg hover:bg-gray-200 transition">取消</button>
                    <button @click="batchUpdateFollower" class="flex-1 bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition">确定修改</button>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
const { createApp, ref, onMounted, computed, watch, nextTick } = Vue;
createApp({
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('companies'); // 侧边栏高亮用
        const loading = ref(false);
        const companies = ref([]);
        const selectedCompanies = ref([]);
        const headerSelectAllRef = ref(null);
        const showBatchEditModal = ref(false);
        const batchFollower = ref('');
        const agents = ref([]); // 内部架构人员列表
        const franchiseBrands = ref([]);
        
        // 搜索条件
        const filters = ref({
            kw: '',
            plate: '',
            area: '',
            follower: '',
            franchise_brand: ''
        });

        // 分页状态
        const pagination = ref({
            page: 1,
            limit: 20,
            total: 0
        });

        const totalPages = computed(() => Math.ceil(pagination.value.total / pagination.value.limit) || 1);

        const jumpPageInput = ref('1');
        watch(() => pagination.value.page, (p) => {
            jumpPageInput.value = String(p);
        }, { immediate: true });

        /** 可点击页码（总页数多时带省略号） */
        const paginationItems = computed(() => {
            const tp = totalPages.value;
            const cp = pagination.value.page;
            if (tp < 1) return [];
            if (tp <= 9) {
                return Array.from({ length: tp }, (_, i) => ({ type: 'page', n: i + 1 }));
            }
            const set = new Set([1, tp]);
            for (let i = cp - 2; i <= cp + 2; i++) {
                if (i >= 1 && i <= tp) set.add(i);
            }
            const sorted = [...set].sort((a, b) => a - b);
            const out = [];
            for (let i = 0; i < sorted.length; i++) {
                if (i > 0 && sorted[i] - sorted[i - 1] > 1) {
                    out.push({ type: 'dots' });
                }
                out.push({ type: 'page', n: sorted[i] });
            }
            return out;
        });

        /** 本页是否已全选（跨页勾选时：只判断当前列表这一页） */
        const allPageRowsSelected = computed(() => {
            const rows = companies.value;
            if (!rows.length) return false;
            return rows.every((c) => selectedCompanies.value.includes(c.id));
        });

        const syncHeaderSelectAllIndeterminate = () => {
            nextTick(() => {
                const el = headerSelectAllRef.value;
                if (!el) return;
                const ids = companies.value.map((c) => c.id);
                if (!ids.length) {
                    el.indeterminate = false;
                    return;
                }
                const n = ids.filter((id) => selectedCompanies.value.includes(id)).length;
                el.indeterminate = n > 0 && n < ids.length;
            });
        };

        watch([selectedCompanies, companies], syncHeaderSelectAllIndeterminate, { deep: true });

        const onToggleSelectAllPage = (e) => {
            const pageIds = companies.value.map((c) => c.id);
            if (!pageIds.length) return;
            if (e.target.checked) {
                selectedCompanies.value = [...new Set([...selectedCompanies.value, ...pageIds])];
            } else {
                const drop = new Set(pageIds);
                selectedCompanies.value = selectedCompanies.value.filter((id) => !drop.has(id));
            }
        };

        const loadData = async () => {
            loading.value = true;
            try {
                // 构建查询参数
                const params = new URLSearchParams({
                    action: 'get_companies',
                    page: String(pagination.value.page),
                    limit: String(Number(pagination.value.limit) || 20),
                    kw: filters.value.kw,
                    plate: filters.value.plate,
                    area: filters.value.area,
                    follower: filters.value.follower,
                    franchise_brand: filters.value.franchise_brand
                });

                const res = await fetch('../api.php?' + params.toString());
                const json = await res.json();
                
                if (json.data) {
                    companies.value = json.data;
                    pagination.value.total = parseInt(json.count || 0);
                } else {
                    // 兼容旧接口格式（如果只返回了数组）
                    companies.value = Array.isArray(json) ? json : [];
                    pagination.value.total = companies.value.length;
                }
            } catch(e) {
                console.error("加载失败", e);
            } finally {
                loading.value = false;
            }
        };

        

        const loadFranchiseBrands = async () => {
            try {
                const res = await fetch('../api.php?action=get_franchise_brands');
                const json = await res.json();
                franchiseBrands.value = Array.isArray(json) ? json : [];
            } catch (e) {
                console.error('加载加盟品牌失败', e);
            }
        };

        const loadAgents = async () => {
            try {
                const res = await fetch('../api.php?action=get_structure');
                const json = await res.json();
                
                if (json.agents) {
                    // 提取所有人员名称，去重
                    const agentNames = [...new Set(json.agents.map(agent => agent.username).filter(Boolean))];
                    agents.value = agentNames.sort();
                }
            } catch(e) {
                console.error("加载人员列表失败", e);
            }
        };

        // 搜索事件（重置页码）
        const handleSearch = () => {
            pagination.value.page = 1;
            loadData();
        };

        // 翻页事件
        const changePage = (newPage) => {
            if (newPage < 1 || newPage > totalPages.value) return;
            pagination.value.page = newPage;
            loadData();
        };

        const jumpToPage = () => {
            const tp = totalPages.value;
            if (tp < 1) return;
            const n = parseInt(String(jumpPageInput.value).trim(), 10);
            if (Number.isNaN(n)) {
                jumpPageInput.value = String(pagination.value.page);
                return;
            }
            changePage(Math.max(1, Math.min(tp, n)));
        };

        const openEdit = (item = null) => {
            const url = item ? `admin_company_edit.php?id=${item.id}` : 'admin_company_edit.php';
            window.open(url, 'company_edit', 'width=900,height=600,top=100,left=100');
        };

        const delComp = async (id) => {
            if(!confirm('确定删除该商户？')) return;
            const fd = new FormData(); fd.append('id', id);
            await fetch('../api.php?action=delete_company', {method:'POST', body:fd});
            loadData();
        };

        const openBatchEditModal = () => {
            batchFollower.value = '';
            showBatchEditModal.value = true;
        };

        const batchUpdateFollower = async () => {
            if (!batchFollower.value.trim()) {
                alert('请选择渠道负责人');
                return;
            }
            
            if(!confirm(`确定将 ${selectedCompanies.value.length} 个商户的渠道负责人修改为 ${batchFollower.value}？`)) return;
            
            try {
                const fd = new FormData();
                fd.append('ids', selectedCompanies.value.join(','));
                fd.append('follower', batchFollower.value);
                
                const res = await fetch('../api.php?action=batch_update_company_follower', {method:'POST', body:fd});
                const data = await res.json();
                
                if (data.status === 'success') {
                    alert('批量修改成功');
                    showBatchEditModal.value = false;
                    selectedCompanies.value = [];
                    loadData();
                } else {
                    alert('修改失败：' + (data.msg || '未知错误'));
                }
            } catch (e) {
                console.error('修改失败', e);
                alert('修改失败，请重试');
            }
        };

        onMounted(() => {
            loadData();
            loadAgents(); // 加载内部架构人员列表
            loadFranchiseBrands();
            syncHeaderSelectAllIndeterminate();
            // 监听子窗口关闭（简单轮询或事件监听太复杂，这里用简单的焦点刷新）
            window.addEventListener('focus', loadData); 
        });

        return {
            sidebarOpen, view, loading, companies, filters, pagination, totalPages, paginationItems,
            jumpPageInput, jumpToPage,
            selectedCompanies, headerSelectAllRef, allPageRowsSelected, onToggleSelectAllPage,
            showBatchEditModal, batchFollower, agents, franchiseBrands,
            loadData, handleSearch, changePage, openEdit, delComp,
            openBatchEditModal, batchUpdateFollower, loadAgents,
        };
    }
}).mount('#app');
</script>
</body>
</html>