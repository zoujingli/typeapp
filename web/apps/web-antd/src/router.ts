import type { RouteRecordRaw } from 'vue-router';
import { createRouter, createWebHashHistory } from 'vue-router';
import { activateRealm, ApiError, isCanceled, loadUser, loginPath, selectTenant, session, sessionExitPath } from './api';

/** 客户端独立工作区；分组与服务端菜单一致，既有页面地址保持兼容。 */
const customerWorkspace: RouteRecordRaw = {
  path: '/', name: 'CustomerWorkspace', component: () => import('./layouts/basic.vue'), redirect: '/profile', meta: { title: '客户端', realm: 'customer', hideInBreadcrumb: true },
  children: [
    { path: 'profile', name: 'CustomerProfile', component: () => import('./views/profile.vue'), meta: { title: '个人工作区' } },
    { path: 'organization', meta: { title: '组织与成员', menuGroup: true }, children: [
      { path: '/tenants', name: 'CustomerTenants', component: () => import('./views/tenants.vue'), meta: { title: '我的租户' } },
      { path: '/members', name: 'CustomerMembers', component: () => import('./views/admin-users.vue'), meta: { title: '租户成员' } },
      { path: '/roles', name: 'CustomerRoles', component: () => import('./views/admin-roles.vue'), meta: { title: '租户角色' } },
    ] },
    { path: 'iot', meta: { title: '物联管理', menuGroup: true }, children: [
      { path: '/products', meta: { title: '产品管理' }, children: [
        { path: '', name: 'CustomerProducts', component: () => import('./views/products.vue'), meta: { title: '产品管理', hideInBreadcrumb: true } },
        { path: ':product/models', name: 'CustomerModels', component: () => import('./views/models.vue'), meta: { title: '物模型版本', hideInMenu: true, activePath: '/products' } },
      ] },
      { path: '/devices', meta: { title: '设备管理' }, children: [
        { path: '', name: 'CustomerDevices', component: () => import('./views/devices.vue'), meta: { title: '设备管理', hideInBreadcrumb: true } },
        { path: ':device', name: 'CustomerDeviceDetail', component: () => import('./views/device-detail.vue'), meta: { title: '设备详情', hideInMenu: true, activePath: '/devices' } },
      ] },
      { path: '/transfers', name: 'CustomerTransfers', component: () => import('./views/transfers.vue'), meta: { title: '设备转移' } },
      { path: '/device-authorizations', name: 'CustomerEnrollments', component: () => import('./views/device-authorizations.vue'), meta: { title: '设备注册授权', requiresTenant: true } },
      { path: '/applications', name: 'CustomerApplications', component: () => import('./views/applications.vue'), meta: { title: '接入应用', requiresTenant: true } },
    ] },
    { path: 'data', meta: { title: '数据与告警', menuGroup: true }, children: [
      { path: '/history', name: 'CustomerHistory', component: () => import('./views/history.vue'), meta: { title: '历史数据' } },
      { path: '/alarm-rules', name: 'CustomerAlarmRules', component: () => import('./views/alarm-rules.vue'), meta: { title: '告警规则' } },
      { path: '/alarms', name: 'CustomerAlarms', component: () => import('./views/alarms.vue'), meta: { title: '告警中心' } },
      { path: '/notifications', name: 'CustomerNotifications', component: () => import('./views/notifications.vue'), meta: { title: '站内通知' } },
    ] },
    { path: 'operations-center', meta: { title: '运维中心', menuGroup: true }, children: [
      { path: '/audit', name: 'CustomerAudit', component: () => import('./views/audit.vue'), meta: { title: '操作审计' } },
      { path: '/operations', name: 'CustomerOperations', component: () => import('./views/operations.vue'), meta: { title: '运行概览' } },
      { path: '/broker-resources', name: 'CustomerBrokerResources', component: () => import('./views/broker-resources.vue'), meta: { title: 'Broker 资源', requiresTenant: true } },
      { path: '/broker-access', name: 'CustomerBrokerAccess', component: () => import('./views/broker-access.vue'), meta: { title: 'Broker 授权', requiresTenant: true } },
      { path: '/broker-quotas', name: 'CustomerBrokerQuotas', component: () => import('./views/broker-quotas.vue'), meta: { title: 'Broker 配额', requiresTenant: true } },
      { path: '/broker-runtime', name: 'CustomerBrokerRuntime', component: () => import('./views/broker-runtime.vue'), meta: { title: 'Broker 运行配置', requiresTenant: true } },
      { path: '/broker-debug', name: 'CustomerBrokerDebug', component: () => import('./views/broker-debug.vue'), meta: { title: 'Broker 调试订阅', requiresTenant: true } },
      { path: '/broker-audit', name: 'CustomerBrokerAudit', component: () => import('./views/audit.vue'), meta: { title: 'Broker 审计', auditScope: 'application-broker', requiresTenant: true } },
    ] },
  ],
};

/** 控制端复用业务页面和基础布局，但拥有自己的路由父级、导航及首页。 */
const adminWorkspace: RouteRecordRaw = {
  path: '/admin', name: 'AdminWorkspace', component: () => import('./layouts/basic.vue'), redirect: '/admin/profile', meta: { title: '控制端', realm: 'admin', hideInBreadcrumb: true },
  children: [
    { path: 'profile', name: 'AdminProfile', component: () => import('./views/profile.vue'), meta: { title: '平台个人工作区' } },
    { path: 'identity', meta: { title: '身份与租户', menuGroup: true }, children: [
      { path: '/admin/users', name: 'AdminUsers', component: () => import('./views/admin-users.vue'), meta: { title: '平台人员' } },
      { path: '/admin/customers', name: 'AdminCustomers', component: () => import('./views/admin-users.vue'), meta: { title: '客户账号' } },
      { path: '/admin/roles', name: 'AdminRoles', component: () => import('./views/admin-roles.vue'), meta: { title: '平台角色' } },
      { path: '/admin/tenants', name: 'AdminTenants', component: () => import('./views/tenants.vue'), meta: { title: '平台租户' } },
    ] },
    { path: 'system', meta: { title: '系统管理', menuGroup: true }, children: [
      { path: '/admin/site', name: 'AdminSite', component: () => import('./views/site-settings.vue'), meta: { title: '站点设置' } },
      { path: '/admin/configuration', name: 'AdminConfiguration', component: () => import('./views/system-configuration.vue'), meta: { title: '系统配置' } },
    ] },
    { path: 'operations-center', meta: { title: '运维中心', menuGroup: true }, children: [
      { path: '/admin/devices', name: 'AdminDevices', component: () => import('./views/devices.vue'), meta: { title: '设备资产' } },
      { path: '/admin/device-authorizations', name: 'AdminEnrollments', component: () => import('./views/device-authorizations.vue'), meta: { title: '设备注册授权' } },
      { path: '/admin/applications', name: 'AdminApplications', component: () => import('./views/applications.vue'), meta: { title: '接入应用' } },
      { path: '/admin/audit', name: 'AdminAudit', component: () => import('./views/audit.vue'), meta: { title: '平台操作审计' } },
      { path: '/admin/operations', name: 'AdminOperations', component: () => import('./views/operations.vue'), meta: { title: '平台运行概览' } },
      { path: '/admin/broker', name: 'AdminBrokerResources', component: () => import('./views/broker-resources.vue'), meta: { title: '平台 Broker 资源' } },
      { path: '/admin/broker-access', name: 'AdminBrokerAccess', component: () => import('./views/broker-access.vue'), meta: { title: '平台 Broker 授权' } },
      { path: '/admin/broker-quotas', name: 'AdminBrokerQuotas', component: () => import('./views/broker-quotas.vue'), meta: { title: '平台 Broker 配额' } },
      { path: '/admin/broker-runtime', name: 'AdminBrokerRuntime', component: () => import('./views/broker-runtime.vue'), meta: { title: '平台 Broker 运行配置' } },
      { path: '/admin/broker-debug', name: 'AdminBrokerDebug', component: () => import('./views/broker-debug.vue'), meta: { title: '平台 Broker 调试订阅' } },
      { path: '/admin/broker-audit', name: 'AdminBrokerAudit', component: () => import('./views/audit.vue'), meta: { title: '平台 Broker 审计', auditScope: 'application-broker' } },
    ] },
  ],
};

/** 独立 Broker 工具维持自己的身份域，不作为两套物联后台的共同布局父级。 */
const brokerWorkspace: RouteRecordRaw = {
  path: '/broker', name: 'BrokerWorkspace', component: () => import('./layouts/basic.vue'), redirect: '/broker/nodes', meta: { title: '独立 Broker', realm: 'broker', hideInBreadcrumb: true },
  children: [
    { path: 'nodes', name: 'BrokerNodes', component: () => import('./views/broker-nodes.vue'), meta: { title: 'Broker 节点' } },
    { path: 'resources', name: 'BrokerResources', component: () => import('./views/broker-resources.vue'), meta: { title: 'Broker 资源' } },
    { path: 'access', name: 'BrokerAccess', component: () => import('./views/broker-access.vue'), meta: { title: 'Broker 授权' } },
    { path: 'quotas', name: 'BrokerQuotas', component: () => import('./views/broker-quotas.vue'), meta: { title: 'Broker 配额' } },
    { path: 'runtime', name: 'BrokerRuntime', component: () => import('./views/broker-runtime.vue'), meta: { title: 'Broker 运行配置' } },
    { path: 'debug', name: 'BrokerDebug', component: () => import('./views/broker-debug.vue'), meta: { title: 'Broker 调试订阅' } },
    { path: 'audit', name: 'BrokerAudit', component: () => import('./views/audit.vue'), meta: { title: 'Broker 审计', auditScope: 'standalone' } },
  ],
};

/** 导航守卫只管理页面上下文，API 仍逐次验证人员身份、角色及客户范围。 */
export const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/login', component: () => import('./views/login.vue'), meta: { title: '用户登录', hideInMenu: true } },
    { path: '/admin/login', component: () => import('./views/login.vue'), meta: { title: '平台登录', hideInMenu: true } },
    { path: '/broker/login', component: () => import('./views/login.vue'), meta: { title: 'Broker 登录', hideInMenu: true } },
    adminWorkspace,
    customerWorkspace,
    brokerWorkspace,
    { path: '/admin/:pathMatch(.*)*', redirect: '/admin/profile' },
    { path: '/broker/:pathMatch(.*)*', redirect: '/broker/nodes' },
    { path: '/:pathMatch(.*)*', redirect: '/profile' },
  ],
});
router.beforeEach(async (to) => {
  const realm = to.meta.realm === 'broker' || to.path.startsWith('/broker/') ? 'broker' : to.meta.realm === 'admin' || to.path.startsWith('/admin/') ? 'admin' : 'customer';
  activateRealm(realm);
  if (to.path === loginPath(realm)) return session.impersonating ? '/profile' : true;
  if (!session.token) return sessionExitPath();
  try { await loadUser(); }
  catch (error) {
    if (isCanceled(error)) return false;
    if (realm === 'customer' && error instanceof ApiError && error.status === 403) {
      selectTenant(null);
      session.notice = '当前租户权限已改变，请重新选择租户。';
      if (to.path !== '/tenants') return '/tenants';
      try { await loadUser(''); return true; }
      catch (retryError) { return isCanceled(retryError) ? false : sessionExitPath(); }
    }
    return sessionExitPath();
  }
  if (to.meta.auditScope === 'standalone' && !session.user?.platform_admin) return '/broker/nodes';
  if (to.meta.requiresTenant && !session.tenant) return '/tenants';
  if (to.matched.at(-1)?.meta.menuGroup) {
    // 父级导航使用当前服务端权限产生的首个入口，不跳到该人员无权访问的固定子页。
    return session.menus.find(menu => menu.path === to.path)?.redirectPath || (realm === 'admin' ? '/admin/profile' : '/profile');
  }
  return true;
});
