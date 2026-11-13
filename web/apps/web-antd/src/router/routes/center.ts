import type { RouteRecordRaw } from 'vue-router';

const basic = () => import('#/layouts/basic.vue');

export const centerRoutes: RouteRecordRaw = { path: '/center', component: basic, meta: { realm: 'system', title: '客户系统' }, children: [
    { path: '/system/groups/center', name: 'system-directory-center', meta: { directory: true, title: '物联中心', icon: 'lucide:cpu' }, children: [
      { path: '/center/products', component: () => import('#/views/center/products.vue'), meta: { title: '产品分类', permission: 'center.products.read' } },
      { path: '/center/enrollments', component: () => import('#/views/center/enrollments.vue'), meta: { title: '注册资格', permission: 'center.enrollments.read' } },
      { path: '/center/devices', component: () => import('#/views/center/devices.vue'), meta: { title: '设备管理', permission: 'center.devices.read' } },
      { path: '/center/devices/:device', component: () => import('#/views/center/device-detail.vue'), meta: { title: '设备详情', permission: 'center.devices.read', activePath: '/center/devices' } },
      { path: '/center/applications', component: () => import('#/views/center/applications.vue'), meta: { title: '接入应用', permission: 'center.applications.read' } },
      { path: '/center/connections', component: () => import('#/views/shared/mqtt-resources.vue'), meta: { title: '连接观察', permission: 'center.connections.read' } },
      { path: '/center/reassignments', component: () => import('#/views/center/reassignments.vue'), meta: { title: '转配接收', permission: 'center.reassignments.read' } },
    ] },
  ] };
