import { Body, Controller, Get, Headers, HttpCode, Post, Res } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Response } from 'express';
import { AuthController } from '../controller/auth.controller';
import { MeDto } from '../modelo/me.dto';
import { LoginDto } from '../modelo/login.dto';

@ApiTags('auth')
@Controller('auth')
export class AuthEndpoint {
  constructor(private readonly authController: AuthController) {}

  @Get('me')
  @ApiOkResponse({ type: MeDto })
  me(@Headers() headers: Record<string, string | string[] | undefined>) {
    const h = (k: string) => {
      const v = headers[k];
      return Array.isArray(v) ? v[0] : v;
    };

    return this.authController.me(h('cookie'));
  }

  @Post('login')
  @HttpCode(200)
  @ApiOkResponse({ type: MeDto })
  async login(
    @Body() body: LoginDto,
    @Res({ passthrough: true }) res: Response,
  ) {
    const result = await this.authController.login({
      email: body.email,
      password: body.password,
    });
    if (!result.ok) return result;

    const opts = {
      httpOnly: true,
      sameSite: 'lax' as const,
      path: '/',
    };
    if (result.token) {
      res.cookie('corejs_token', result.token, opts);
    }
    return result;
  }

  @Post('logout')
  @HttpCode(200)
  logout(@Res({ passthrough: true }) res: Response) {
    res.clearCookie('corejs_token', { path: '/' });
    return { ok: true, data: { done: true } };
  }
}
