import { AnimationController } from '@ionic/angular';

export const fadeTransition = (_: HTMLElement, opts: any) => {
  const animationCtrl = new AnimationController();

  const entering = animationCtrl.create()
    .addElement(opts.enteringEl)
    .fromTo('opacity', '0', '1')
    .duration(300)
    .easing('ease-in-out');

  const leaving = animationCtrl.create()
    .addElement(opts.leavingEl)
    .fromTo('opacity', '1', '0')
    .duration(300)
    .easing('ease-in-out');

  return animationCtrl.create()
    .addAnimation([entering, leaving]);
};
